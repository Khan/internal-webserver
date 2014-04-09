<?php

final class ManiphestTaskEditController extends ManiphestController {

  private $id;

  public function willProcessRequest(array $data) {
    $this->id = idx($data, 'id');
  }

  public function processRequest() {

    $request = $this->getRequest();
    $user = $request->getUser();
    $response_type = $request->getStr('responseType', 'task');

    $can_edit_assign = $this->hasApplicationCapability(
      ManiphestCapabilityEditAssign::CAPABILITY);
    $can_edit_policies = $this->hasApplicationCapability(
      ManiphestCapabilityEditPolicies::CAPABILITY);
    $can_edit_priority = $this->hasApplicationCapability(
      ManiphestCapabilityEditPriority::CAPABILITY);
    $can_edit_projects = $this->hasApplicationCapability(
      ManiphestCapabilityEditProjects::CAPABILITY);
    $can_edit_status = $this->hasApplicationCapability(
      ManiphestCapabilityEditStatus::CAPABILITY);

    $files = array();
    $parent_task = null;
    $template_id = null;

    if ($this->id) {
      $task = id(new ManiphestTaskQuery())
        ->setViewer($user)
        ->requireCapabilities(
          array(
            PhabricatorPolicyCapability::CAN_VIEW,
            PhabricatorPolicyCapability::CAN_EDIT,
          ))
        ->withIDs(array($this->id))
        ->executeOne();
      if (!$task) {
        return new Aphront404Response();
      }
    } else {
      $task = ManiphestTask::initializeNewTask($user);

      // We currently do not allow you to set the task status when creating
      // a new task, although now that statuses are custom it might make
      // sense.
      $can_edit_status = false;

      // These allow task creation with defaults.
      if (!$request->isFormPost()) {
        $task->setTitle($request->getStr('title'));

        if ($can_edit_projects) {
          $projects = $request->getStr('projects');
          if ($projects) {
            $tokens = $request->getStrList('projects');

            foreach ($tokens as $key => $token) {
              $tokens[$key] = '#'.$token;
            }

            $default_projects = id(new PhabricatorObjectQuery())
              ->setViewer($user)
              ->withNames($tokens)
              ->execute();
            $default_projects = mpull($default_projects, 'getPHID');

            if ($default_projects) {
              $task->setProjectPHIDs($default_projects);
            }
          }
        }

        if ($can_edit_priority) {
          $priority = $request->getInt('priority');
          if ($priority !== null) {
            $priority_map = ManiphestTaskPriority::getTaskPriorityMap();
            if (isset($priority_map[$priority])) {
                $task->setPriority($priority);
            }
          }
        }

        $task->setDescription($request->getStr('description'));

        if ($can_edit_assign) {
          $assign = $request->getStr('assign');
          if (strlen($assign)) {
            $assign_user = id(new PhabricatorPeopleQuery())
              ->setViewer($user)
              ->withUsernames(array($assign))
              ->executeOne();
            if (!$assign_user) {
              $assign_user = id(new PhabricatorPeopleQuery())
                ->setViewer($user)
                ->withPHIDs(array($assign))
                ->executeOne();
            }

            if ($assign_user) {
              $task->setOwnerPHID($assign_user->getPHID());
            }
          }
        }
      }

      $file_phids = $request->getArr('files', array());
      if (!$file_phids) {
        // Allow a single 'file' key instead, mostly since Mac OS X urlencodes
        // square brackets in URLs when passed to 'open', so you can't 'open'
        // a URL like '?files[]=xyz' and have PHP interpret it correctly.
        $phid = $request->getStr('file');
        if ($phid) {
          $file_phids = array($phid);
        }
      }

      if ($file_phids) {
        $files = id(new PhabricatorFileQuery())
          ->setViewer($user)
          ->withPHIDs($file_phids)
          ->execute();
      }

      $template_id = $request->getInt('template');

      // You can only have a parent task if you're creating a new task.
      $parent_id = $request->getInt('parent');
      if ($parent_id) {
        $parent_task = id(new ManiphestTaskQuery())
          ->setViewer($user)
          ->withIDs(array($parent_id))
          ->executeOne();
        if (!$template_id) {
          $template_id = $parent_id;
        }
      }
    }

    $errors = array();
    $e_title = true;

    $field_list = PhabricatorCustomField::getObjectFields(
      $task,
      PhabricatorCustomField::ROLE_EDIT);
    $field_list->setViewer($user);
    $field_list->readFieldsFromStorage($task);

    $aux_fields = $field_list->getFields();

    if ($request->isFormPost()) {
      $changes = array();

      $new_title = $request->getStr('title');
      $new_desc = $request->getStr('description');
      $new_status = $request->getStr('status');

      if (!$task->getID()) {
        $workflow = 'create';
      } else {
        $workflow = '';
      }

      $changes[ManiphestTransaction::TYPE_TITLE] = $new_title;
      $changes[ManiphestTransaction::TYPE_DESCRIPTION] = $new_desc;

      if ($can_edit_status) {
        $changes[ManiphestTransaction::TYPE_STATUS] = $new_status;
      } else if (!$task->getID()) {
        // Create an initial status transaction for the burndown chart.
        // TODO: We can probably remove this once Facts comes online.
        $changes[ManiphestTransaction::TYPE_STATUS] = $task->getStatus();
      }

      $owner_tokenizer = $request->getArr('assigned_to');
      $owner_phid = reset($owner_tokenizer);

      if (!strlen($new_title)) {
        $e_title = pht('Required');
        $errors[] = pht('Title is required.');
      }

      $old_values = array();
      foreach ($aux_fields as $aux_arr_key => $aux_field) {
        // TODO: This should be buildFieldTransactionsFromRequest() once we
        // switch to ApplicationTransactions properly.

        $aux_old_value = $aux_field->getOldValueForApplicationTransactions();
        $aux_field->readValueFromRequest($request);
        $aux_new_value = $aux_field->getNewValueForApplicationTransactions();

        // TODO: We're faking a call to the ApplicaitonTransaction validation
        // logic here. We need valid objects to pass, but they aren't used
        // in a meaningful way. For now, build User objects. Once the Maniphest
        // objects exist, this will switch over automatically. This is a big
        // hack but shouldn't be long for this world.
        $placeholder_editor = new PhabricatorUserProfileEditor();

        $field_errors = $aux_field->validateApplicationTransactions(
          $placeholder_editor,
          PhabricatorTransactions::TYPE_CUSTOMFIELD,
          array(
            id(new ManiphestTransaction())
              ->setOldValue($aux_old_value)
              ->setNewValue($aux_new_value),
          ));

        foreach ($field_errors as $error) {
          $errors[] = $error->getMessage();
        }

        $old_values[$aux_field->getFieldKey()] = $aux_old_value;
      }

      if ($errors) {
        $task->setTitle($new_title);
        $task->setDescription($new_desc);
        $task->setPriority($request->getInt('priority'));
        $task->setOwnerPHID($owner_phid);
        $task->setCCPHIDs($request->getArr('cc'));
        $task->setProjectPHIDs($request->getArr('projects'));
      } else {

        if ($can_edit_priority) {
          $changes[ManiphestTransaction::TYPE_PRIORITY] =
            $request->getInt('priority');
        }
        if ($can_edit_assign) {
          $changes[ManiphestTransaction::TYPE_OWNER] = $owner_phid;
        }

        $changes[ManiphestTransaction::TYPE_CCS] = $request->getArr('cc');

        if ($can_edit_projects) {
          $projects = $request->getArr('projects');
          $changes[ManiphestTransaction::TYPE_PROJECTS] =
            $projects;
          $column_phid = $request->getStr('columnPHID');
          // allow for putting a task in a project column at creation -only-
          if (!$task->getID() && $column_phid && $projects) {
            $column = id(new PhabricatorProjectColumnQuery())
              ->setViewer($user)
              ->withProjectPHIDs($projects)
              ->withPHIDs(array($column_phid))
              ->executeOne();
            if ($column) {
              $changes[ManiphestTransaction::TYPE_PROJECT_COLUMN] =
                array(
                  'new' => array(
                    'projectPHID' => $column->getProjectPHID(),
                    'columnPHIDs' => array($column_phid)),
                  'old' => array(
                    'projectPHID' => $column->getProjectPHID(),
                    'columnPHIDs' => array()));
            }
          }
        }

        if ($can_edit_policies) {
          $changes[PhabricatorTransactions::TYPE_VIEW_POLICY] =
            $request->getStr('viewPolicy');
          $changes[PhabricatorTransactions::TYPE_EDIT_POLICY] =
            $request->getStr('editPolicy');
        }

        if ($files) {
          $file_map = mpull($files, 'getPHID');
          $file_map = array_fill_keys($file_map, array());
          $changes[ManiphestTransaction::TYPE_ATTACH] = array(
            PhabricatorFilePHIDTypeFile::TYPECONST => $file_map,
          );
        }

        $template = new ManiphestTransaction();
        $transactions = array();

        foreach ($changes as $type => $value) {
          $transaction = clone $template;
          $transaction->setTransactionType($type);
          if ($type == ManiphestTransaction::TYPE_PROJECT_COLUMN) {
            $transaction->setNewValue($value['new']);
            $transaction->setOldValue($value['old']);
          } else {
            $transaction->setNewValue($value);
          }
          $transactions[] = $transaction;
        }

        if ($aux_fields) {
          foreach ($aux_fields as $aux_field) {
            $transaction = clone $template;
            $transaction->setTransactionType(
              PhabricatorTransactions::TYPE_CUSTOMFIELD);
            $aux_key = $aux_field->getFieldKey();
            $transaction->setMetadataValue('customfield:key', $aux_key);
            $old = idx($old_values, $aux_key);
            $new = $aux_field->getNewValueForApplicationTransactions();

            $transaction->setOldValue($old);
            $transaction->setNewValue($new);

            $transactions[] = $transaction;
          }
        }

        if ($transactions) {
          $is_new = !$task->getID();

          $event = new PhabricatorEvent(
            PhabricatorEventType::TYPE_MANIPHEST_WILLEDITTASK,
            array(
              'task'          => $task,
              'new'           => $is_new,
              'transactions'  => $transactions,
            ));
          $event->setUser($user);
          $event->setAphrontRequest($request);
          PhutilEventEngine::dispatchEvent($event);

          $task = $event->getValue('task');
          $transactions = $event->getValue('transactions');

          $editor = id(new ManiphestTransactionEditor())
            ->setActor($user)
            ->setContentSourceFromRequest($request)
            ->setContinueOnNoEffect(true)
            ->applyTransactions($task, $transactions);

          $event = new PhabricatorEvent(
            PhabricatorEventType::TYPE_MANIPHEST_DIDEDITTASK,
            array(
              'task'          => $task,
              'new'           => $is_new,
              'transactions'  => $transactions,
            ));
          $event->setUser($user);
          $event->setAphrontRequest($request);
          PhutilEventEngine::dispatchEvent($event);
        }


        if ($parent_task) {
          id(new PhabricatorEdgeEditor())
            ->setActor($user)
            ->addEdge(
              $parent_task->getPHID(),
              PhabricatorEdgeConfig::TYPE_TASK_DEPENDS_ON_TASK,
              $task->getPHID())
            ->save();
          $workflow = $parent_task->getID();
        }

        if ($request->isAjax()) {
          switch ($response_type) {
            case 'card':
              $owner = null;
              if ($task->getOwnerPHID()) {
                $owner = id(new PhabricatorHandleQuery())
                  ->setViewer($user)
                  ->withPHIDs(array($task->getOwnerPHID()))
                  ->executeOne();
              }
              $tasks = id(new ProjectBoardTaskCard())
                ->setViewer($user)
                ->setTask($task)
                ->setOwner($owner)
                ->setCanEdit(true)
                ->getItem();
              $column_phid = $request->getStr('columnPHID');
              $column = id(new PhabricatorProjectColumnQuery())
                ->setViewer($user)
                ->withPHIDs(array($column_phid))
                ->executeOne();
              if ($column->isDefaultColumn()) {
                $column_tasks = array();
                $potential_col_tasks = id(new ManiphestTaskQuery())
                  ->setViewer($user)
                  ->withAllProjects(array($column->getProjectPHID()))
                  ->withStatuses(ManiphestTaskStatus::getOpenStatusConstants())
                  ->setOrderBy(ManiphestTaskQuery::ORDER_PRIORITY)
                  ->execute();
                $potential_col_tasks = mpull(
                  $potential_col_tasks,
                  null,
                  'getPHID');
                $potential_task_phids = array_keys($potential_col_tasks);
                if ($potential_task_phids) {
                  $edge_type = PhabricatorEdgeConfig::TYPE_OBJECT_HAS_COLUMN;
                  $edge_query = id(new PhabricatorEdgeQuery())
                    ->withSourcePHIDs($potential_task_phids)
                    ->withEdgeTypes(array($edge_type));
                  $edges = $edge_query->execute();
                  foreach ($potential_col_tasks as $task_phid => $curr_task) {
                    $curr_column_phids = $edges[$task_phid][$edge_type];
                    $curr_column_phid = head_key($curr_column_phids);
                    if (!$curr_column_phid ||
                        $curr_column_phid == $column_phid) {
                      $column_tasks[] = $curr_task;
                    }
                  }
                }
              } else {
                $column_task_phids = PhabricatorEdgeQuery::loadDestinationPHIDs(
                  $column_phid,
                  PhabricatorEdgeConfig::TYPE_COLUMN_HAS_OBJECT);
                $column_tasks = id(new ManiphestTaskQuery())
                  ->setViewer($user)
                  ->withPHIDs($column_task_phids)
                  ->withStatuses(ManiphestTaskStatus::getOpenStatusConstants())
                  ->setOrderBy(ManiphestTaskQuery::ORDER_PRIORITY)
                  ->execute();
              }
              $column_task_phids = mpull($column_tasks, 'getPHID');
              $task_phid = $task->getPHID();
              $after_phid = null;
              foreach ($column_task_phids as $phid) {
                if ($phid == $task_phid) {
                  break;
                }
                $after_phid = $phid;
              }
              $data = array(
                'insertAfterPHID' => $after_phid);
              break;
            case 'task':
            default:
              $tasks = $this->renderSingleTask($task);
              $data = array();
              break;
          }
          return id(new AphrontAjaxResponse())->setContent(
            array(
              'tasks' => $tasks,
              'data' => $data,
            ));
        }

        $redirect_uri = '/T'.$task->getID();

        if ($workflow) {
          $redirect_uri .= '?workflow='.$workflow;
        }

        return id(new AphrontRedirectResponse())
          ->setURI($redirect_uri);
      }
    } else {
      if (!$task->getID()) {
        $task->setCCPHIDs(array(
          $user->getPHID(),
        ));
        if ($template_id) {
          $template_task = id(new ManiphestTaskQuery())
            ->setViewer($user)
            ->withIDs(array($template_id))
            ->executeOne();
          if ($template_task) {
            $task->setCCPHIDs($template_task->getCCPHIDs());
            $task->setProjectPHIDs($template_task->getProjectPHIDs());
            $task->setOwnerPHID($template_task->getOwnerPHID());
            $task->setPriority($template_task->getPriority());
            $task->setViewPolicy($template_task->getViewPolicy());
            $task->setEditPolicy($template_task->getEditPolicy());

            $template_fields = PhabricatorCustomField::getObjectFields(
              $template_task,
              PhabricatorCustomField::ROLE_EDIT);

            $fields = $template_fields->getFields();
            foreach ($fields as $key => $field) {
              if (!$field->shouldCopyWhenCreatingSimilarTask()) {
                unset($fields[$key]);
              }
              if (empty($aux_fields[$key])) {
                unset($fields[$key]);
              }
            }

            if ($fields) {
              id(new PhabricatorCustomFieldList($fields))
                ->setViewer($user)
                ->readFieldsFromStorage($template_task);

              foreach ($fields as $key => $field) {
                $aux_fields[$key]->setValueFromStorage(
                  $field->getValueForStorage());
              }
            }
          }
        }
      }
    }

    $phids = array_merge(
      array($task->getOwnerPHID()),
      $task->getCCPHIDs(),
      $task->getProjectPHIDs());

    if ($parent_task) {
      $phids[] = $parent_task->getPHID();
    }

    $phids = array_filter($phids);
    $phids = array_unique($phids);

    $handles = $this->loadViewerHandles($phids);

    $error_view = null;
    if ($errors) {
      $error_view = new AphrontErrorView();
      $error_view->setErrors($errors);
    }

    $priority_map = ManiphestTaskPriority::getTaskPriorityMap();

    if ($task->getOwnerPHID()) {
      $assigned_value = array($handles[$task->getOwnerPHID()]);
    } else {
      $assigned_value = array();
    }

    if ($task->getCCPHIDs()) {
      $cc_value = array_select_keys($handles, $task->getCCPHIDs());
    } else {
      $cc_value = array();
    }

    if ($task->getProjectPHIDs()) {
      $projects_value = array_select_keys($handles, $task->getProjectPHIDs());
    } else {
      $projects_value = array();
    }

    $cancel_id = nonempty($task->getID(), $template_id);
    if ($cancel_id) {
      $cancel_uri = '/T'.$cancel_id;
    } else {
      $cancel_uri = '/maniphest/';
    }

    if ($task->getID()) {
      $button_name = pht('Save Task');
      $header_name = pht('Edit Task');
    } else if ($parent_task) {
      $cancel_uri = '/T'.$parent_task->getID();
      $button_name = pht('Create Task');
      $header_name = pht('Create New Subtask');
    } else {
      $button_name = pht('Create Task');
      $header_name = pht('Create New Task');
    }

    require_celerity_resource('maniphest-task-edit-css');

    $project_tokenizer_id = celerity_generate_unique_node_id();

    $form = new AphrontFormView();
    $form
      ->setUser($user)
      ->addHiddenInput('template', $template_id)
      ->addHiddenInput('responseType', $response_type)
      ->addHiddenInput('columnPHID', $request->getStr('columnPHID'));

    if ($parent_task) {
      $form
        ->appendChild(
          id(new AphrontFormStaticControl())
            ->setLabel(pht('Parent Task'))
            ->setValue($handles[$parent_task->getPHID()]->getFullName()))
        ->addHiddenInput('parent', $parent_task->getID());
    }

    $form
      ->appendChild(
        id(new AphrontFormTextAreaControl())
          ->setLabel(pht('Title'))
          ->setName('title')
          ->setError($e_title)
          ->setHeight(AphrontFormTextAreaControl::HEIGHT_VERY_SHORT)
          ->setValue($task->getTitle()));

    if ($can_edit_status) {
      $form
        ->appendChild(
          id(new AphrontFormSelectControl())
            ->setLabel(pht('Status'))
            ->setName('status')
            ->setValue($task->getStatus())
            ->setOptions(ManiphestTaskStatus::getTaskStatusMap()));
    }

    $policies = id(new PhabricatorPolicyQuery())
      ->setViewer($user)
      ->setObject($task)
      ->execute();

    if ($can_edit_assign) {
      $form->appendChild(
        id(new AphrontFormTokenizerControl())
          ->setLabel(pht('Assigned To'))
          ->setName('assigned_to')
          ->setValue($assigned_value)
          ->setUser($user)
          ->setDatasource('/typeahead/common/users/')
          ->setLimit(1));
    }

    $form
      ->appendChild(
        id(new AphrontFormTokenizerControl())
          ->setLabel(pht('CC'))
          ->setName('cc')
          ->setValue($cc_value)
          ->setUser($user)
          ->setDatasource('/typeahead/common/mailable/'));

    if ($can_edit_priority) {
      $form
        ->appendChild(
          id(new AphrontFormSelectControl())
            ->setLabel(pht('Priority'))
            ->setName('priority')
            ->setOptions($priority_map)
            ->setValue($task->getPriority()));
    }

    if ($can_edit_policies) {
      $form
        ->appendChild(
          id(new AphrontFormPolicyControl())
            ->setUser($user)
            ->setCapability(PhabricatorPolicyCapability::CAN_VIEW)
            ->setPolicyObject($task)
            ->setPolicies($policies)
            ->setName('viewPolicy'))
        ->appendChild(
          id(new AphrontFormPolicyControl())
            ->setUser($user)
            ->setCapability(PhabricatorPolicyCapability::CAN_EDIT)
            ->setPolicyObject($task)
            ->setPolicies($policies)
            ->setName('editPolicy'));
    }

    if ($can_edit_projects) {
      $form
        ->appendChild(
          id(new AphrontFormTokenizerControl())
            ->setLabel(pht('Projects'))
            ->setName('projects')
            ->setValue($projects_value)
            ->setID($project_tokenizer_id)
            ->setCaption(
              javelin_tag(
                'a',
                array(
                  'href'        => '/project/create/',
                  'mustcapture' => true,
                  'sigil'       => 'project-create',
                ),
                pht('Create New Project')))
            ->setDatasource('/typeahead/common/projects/'));
    }

    $field_list->appendFieldsToForm($form);

    require_celerity_resource('aphront-error-view-css');

    Javelin::initBehavior('project-create', array(
      'tokenizerID' => $project_tokenizer_id,
    ));

    if ($files) {
      $file_display = mpull($files, 'getName');
      $file_display = phutil_implode_html(phutil_tag('br'), $file_display);

      $form->appendChild(
        id(new AphrontFormMarkupControl())
          ->setLabel(pht('Files'))
          ->setValue($file_display));

      foreach ($files as $ii => $file) {
        $form->addHiddenInput('files['.$ii.']', $file->getPHID());
      }
    }


    $description_control = new PhabricatorRemarkupControl();
    // "Upsell" creating tasks via email in create flows if the instance is
    // configured for this awesomeness.
    $email_create = PhabricatorEnv::getEnvConfig(
      'metamta.maniphest.public-create-email');
    if (!$task->getID() && $email_create) {
      $email_hint = pht(
        'You can also create tasks by sending an email to: %s',
        phutil_tag('tt', array(), $email_create));
      $description_control->setCaption($email_hint);
    }

    $description_control
      ->setLabel(pht('Description'))
      ->setName('description')
      ->setID('description-textarea')
      ->setValue($task->getDescription())
      ->setUser($user);

    $form
      ->appendChild($description_control);


    if ($request->isAjax()) {
      $dialog = id(new AphrontDialogView())
        ->setUser($user)
        ->setWidth(AphrontDialogView::WIDTH_FULL)
        ->setTitle($header_name)
        ->appendChild(
          array(
            $error_view,
            $form->buildLayoutView(),
          ))
        ->addCancelButton($cancel_uri)
        ->addSubmitButton($button_name);
      return id(new AphrontDialogResponse())->setDialog($dialog);
    }

    $form
      ->appendChild(
        id(new AphrontFormSubmitControl())
          ->addCancelButton($cancel_uri)
          ->setValue($button_name));

    $form_box = id(new PHUIObjectBoxView())
      ->setHeaderText($header_name)
      ->setFormErrors($errors)
      ->setForm($form);

    $preview = id(new PHUIRemarkupPreviewPanel())
      ->setHeader(pht('Description Preview'))
      ->setControlID('description-textarea')
      ->setPreviewURI($this->getApplicationURI('task/descriptionpreview/'));

    if ($task->getID()) {
      $page_objects = array($task->getPHID());
    } else {
      $page_objects = array();
    }

    $crumbs = $this->buildApplicationCrumbs();

    if ($task->getID()) {
      $crumbs->addTextCrumb('T'.$task->getID(), '/T'.$task->getID());
    }

    $crumbs->addTextCrumb($header_name);

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $form_box,
        $preview,
      ),
      array(
        'title' => $header_name,
        'pageObjects' => $page_objects,
        'device' => true,
      ));
  }
}
