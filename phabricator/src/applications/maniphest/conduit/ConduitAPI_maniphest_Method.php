<?php

/**
 * @group conduit
 */
abstract class ConduitAPI_maniphest_Method extends ConduitAPIMethod {

  public function getApplication() {
    return PhabricatorApplication::getByClass(
      'PhabricatorApplicationManiphest');
  }

  public function defineErrorTypes() {
    return array(
      'ERR-INVALID-PARAMETER' => 'Missing or malformed parameter.'
    );
  }

  protected function buildTaskInfoDictionary(ManiphestTask $task) {
    $results = $this->buildTaskInfoDictionaries(array($task));
    return idx($results, $task->getPHID());
  }

  protected function getTaskFields($is_new) {
    $fields = array();

    if (!$is_new) {
      $fields += array(
        'id'        => 'optional int',
        'phid'      => 'optional int',
      );
    }

    $fields += array(
      'title'         => $is_new ? 'required string' : 'optional string',
      'description'   => 'optional string',
      'ownerPHID'     => 'optional phid',
      'ccPHIDs'       => 'optional list<phid>',
      'priority'      => 'optional int',
      'projectPHIDs'  => 'optional list<phid>',
      'filePHIDs'     => 'optional list<phid>',
      'auxiliary'     => 'optional dict',
    );

    if (!$is_new) {
      $fields += array(
        'status'    => 'optional int',
        'comments'  => 'optional string',
      );
    }

    return $fields;
  }

  protected function applyRequest(
    ManiphestTask $task,
    ConduitAPIRequest $request,
    $is_new) {

    $changes = array();

    if ($is_new) {
      $task->setTitle((string)$request->getValue('title'));
      $task->setDescription((string)$request->getValue('description'));
      $changes[ManiphestTransactionType::TYPE_STATUS] =
        ManiphestTaskStatus::STATUS_OPEN;
    } else {

      $comments = $request->getValue('comments');
      if (!$is_new && $comments !== null) {
        $changes[ManiphestTransactionType::TYPE_NONE] = null;
      }

      $title = $request->getValue('title');
      if ($title !== null) {
        $changes[ManiphestTransactionType::TYPE_TITLE] = $title;
      }

      $desc = $request->getValue('description');
      if ($desc !== null) {
        $changes[ManiphestTransactionType::TYPE_DESCRIPTION] = $desc;
      }

      $status = $request->getValue('status');
      if ($status !== null) {
        $valid_statuses = ManiphestTaskStatus::getTaskStatusMap();
        if (!isset($valid_statuses[$status])) {
          throw id(new ConduitException('ERR-INVALID-PARAMETER'))
            ->setErrorDescription('Status set to invalid value.');
        }
        $changes[ManiphestTransactionType::TYPE_STATUS] = $status;
      }
    }

    $priority = $request->getValue('priority');
    if ($priority !== null) {
      $valid_priorities = ManiphestTaskPriority::getTaskPriorityMap();
      if (!isset($valid_priorities[$priority])) {
        throw id(new ConduitException('ERR-INVALID-PARAMETER'))
          ->setErrorDescription('Priority set to invalid value.');
      }
      $changes[ManiphestTransactionType::TYPE_PRIORITY] = $priority;
    }

    $owner_phid = $request->getValue('ownerPHID');
    if ($owner_phid !== null) {
      $this->validatePHIDList(array($owner_phid),
                              PhabricatorPeoplePHIDTypeUser::TYPECONST,
                              'ownerPHID');
      $changes[ManiphestTransactionType::TYPE_OWNER] = $owner_phid;
    }

    $ccs = $request->getValue('ccPHIDs');
    if ($ccs !== null) {
      $this->validatePHIDList($ccs,
                              PhabricatorPeoplePHIDTypeUser::TYPECONST,
                              'ccPHIDS');
      $changes[ManiphestTransactionType::TYPE_CCS] = $ccs;
    }

    $project_phids = $request->getValue('projectPHIDs');
    if ($project_phids !== null) {
      $this->validatePHIDList($project_phids,
                              PhabricatorProjectPHIDTypeProject::TYPECONST,
                              'projectPHIDS');
      $changes[ManiphestTransactionType::TYPE_PROJECTS] = $project_phids;
    }

    $file_phids = $request->getValue('filePHIDs');
    if ($file_phids !== null) {
      $this->validatePHIDList($file_phids,
                              PhabricatorFilePHIDTypeFile::TYPECONST,
                              'filePHIDS');
      $file_map = array_fill_keys($file_phids, true);
      $attached = $task->getAttached();
      $attached[PhabricatorFilePHIDTypeFile::TYPECONST] = $file_map;

      $changes[ManiphestTransactionType::TYPE_ATTACH] = $attached;
    }

    $content_source = PhabricatorContentSource::newForSource(
      PhabricatorContentSource::SOURCE_CONDUIT,
      array());

    $template = new ManiphestTransaction();
    $template->setContentSource($content_source);
    $template->setAuthorPHID($request->getUser()->getPHID());

    $transactions = array();
    foreach ($changes as $type => $value) {
      $transaction = clone $template;
      $transaction->setTransactionType($type);
      $transaction->setNewValue($value);
      if ($type == ManiphestTransactionType::TYPE_NONE) {
        $transaction->setComments($comments);
      }
      $transactions[] = $transaction;
    }

    $field_list = PhabricatorCustomField::getObjectFields(
      $task,
      PhabricatorCustomField::ROLE_EDIT);

    $auxiliary = $request->getValue('auxiliary');
    if ($auxiliary) {
      foreach ($field_list->getFields() as $key => $field) {
        if (!array_key_exists($key, $auxiliary)) {
          continue;
        }
        $transaction = clone $template;
        $transaction->setTransactionType(
          ManiphestTransactionType::TYPE_AUXILIARY);
        $transaction->setMetadataValue('aux:key', $key);
        $transaction->setOldValue(
          $field->getOldValueForApplicationTransactions());
        $transaction->setNewValue($auxiliary[$key]);
        $transactions[] = $transaction;
      }
    }

    if (!$transactions) {
      return;
    }

    $event = new PhabricatorEvent(
      PhabricatorEventType::TYPE_MANIPHEST_WILLEDITTASK,
      array(
        'task'          => $task,
        'new'           => $is_new,
        'transactions'  => $transactions,
      ));
    $event->setUser($request->getUser());
    $event->setConduitRequest($request);
    PhutilEventEngine::dispatchEvent($event);

    $task = $event->getValue('task');
    $transactions = $event->getValue('transactions');

    $editor = new ManiphestTransactionEditor();
    $editor->setActor($request->getUser());
    $editor->setAuxiliaryFields($field_list->getFields());
    $editor->applyTransactions($task, $transactions);

    $event = new PhabricatorEvent(
      PhabricatorEventType::TYPE_MANIPHEST_DIDEDITTASK,
      array(
        'task'          => $task,
        'new'           => $is_new,
        'transactions'  => $transactions,
      ));
    $event->setUser($request->getUser());
    $event->setConduitRequest($request);
    PhutilEventEngine::dispatchEvent($event);

  }

  protected function buildTaskInfoDictionaries(array $tasks) {
    assert_instances_of($tasks, 'ManiphestTask');
    if (!$tasks) {
      return array();
    }

    $task_phids = mpull($tasks, 'getPHID');

    $all_deps = id(new PhabricatorEdgeQuery())
      ->withSourcePHIDs($task_phids)
      ->withEdgeTypes(array(PhabricatorEdgeConfig::TYPE_TASK_DEPENDS_ON_TASK));
    $all_deps->execute();

    $result = array();
    foreach ($tasks as $task) {
      // TODO: Batch this get as CustomField gets cleaned up.
      $field_list = PhabricatorCustomField::getObjectFields(
        $task,
        PhabricatorCustomField::ROLE_EDIT);
      $field_list->readFieldsFromStorage($task);

      $auxiliary = mpull(
        $field_list->getFields(),
        'getValueForStorage',
        'getFieldKey');

      $task_deps = $all_deps->getDestinationPHIDs(
        array($task->getPHID()),
        array(PhabricatorEdgeConfig::TYPE_TASK_DEPENDS_ON_TASK));

      $result[$task->getPHID()] = array(
        'id'           => $task->getID(),
        'phid'         => $task->getPHID(),
        'authorPHID'   => $task->getAuthorPHID(),
        'ownerPHID'    => $task->getOwnerPHID(),
        'ccPHIDs'      => $task->getCCPHIDs(),
        'status'       => $task->getStatus(),
        'priority'     => ManiphestTaskPriority::getTaskPriorityName(
          $task->getPriority()),
        'title'        => $task->getTitle(),
        'description'  => $task->getDescription(),
        'projectPHIDs' => $task->getProjectPHIDs(),
        'uri'          => PhabricatorEnv::getProductionURI('/T'.$task->getID()),
        'auxiliary'    => $auxiliary,

        'objectName'   => 'T'.$task->getID(),
        'dateCreated'  => $task->getDateCreated(),
        'dateModified' => $task->getDateModified(),
        'dependsOnTaskPHIDs' => $task_deps,
      );
    }

    return $result;
  }

  /**
   * Note this is a temporary stop gap since its easy to make malformed Tasks.
   * Long-term, the values set in @{method:defineParamTypes} will be used to
   * validate data implicitly within the larger Conduit application.
   *
   * TODO -- remove this in favor of generalized Conduit hotness
   */
  private function validatePHIDList(array $phid_list, $phid_type, $field) {
    $phid_groups = phid_group_by_type($phid_list);
    unset($phid_groups[$phid_type]);
    if (!empty($phid_groups)) {
      throw id(new ConduitException('ERR-INVALID-PARAMETER'))
        ->setErrorDescription(
          'One or more PHIDs were invalid for '.$field.'.');
    }

    return true;
  }

}
