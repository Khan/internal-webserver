<?php

/**
 * @group conduit
 */
final class ConduitAPI_conpherence_updatethread_Method
  extends ConduitAPI_conpherence_Method {

  public function getMethodDescription() {
    return pht('Update an existing conpherence thread.');
  }

  public function defineParamTypes() {
    return array(
      'id' => 'optional int',
      'phid' => 'optional phid',
      'title' => 'optional string',
      'message' => 'optional string',
      'addParticipantPHIDs' => 'optional list<phids>',
      'removeParticipantPHID' => 'optional phid'
    );
  }

  public function defineReturnType() {
    return 'bool';
  }

  public function defineErrorTypes() {
    return array(
      'ERR_USAGE_NO_THREAD_ID' => pht(
        'You must specify a thread id or thread phid to query transactions '.
        'from.'),
      'ERR_USAGE_THREAD_NOT_FOUND' => pht(
        'Thread does not exist or logged in user can not see it.'),
      'ERR_USAGE_ONLY_SELF_REMOVE' => pht(
        'Only a user can remove themselves from a thread.'),
      'ERR_USAGE_NO_UPDATES' => pht(
        'You must specify data that actually updates the conpherence.')
    );
  }

  protected function execute(ConduitAPIRequest $request) {
    $user = $request->getUser();
    $id = $request->getValue('id');
    $phid = $request->getValue('phid');
    $query = id(new ConpherenceThreadQuery())
      ->setViewer($user)
      ->needFilePHIDs(true);
    if ($id) {
      $query->withIDs(array($id));
    } else if ($phid) {
      $query->withPHIDs(array($phid));
    } else {
      throw new ConduitException('ERR_USAGE_NO_THREAD_ID');
    }
    $conpherence = $query->executeOne();
    if (!$conpherence) {
      throw new ConduitException('ERR_USAGE_THREAD_NOT_FOUND');
    }

    $source = PhabricatorContentSource::newFromConduitRequest($request);
    $editor = id(new ConpherenceEditor())
      ->setContentSource($source)
      ->setActor($user);
    $xactions = array();
    $add_participant_phids = $request->getValue('addParticipantPHIDs', array());
    $remove_participant_phid = $request->getValue('removeParticipantPHID');
    $message = $request->getValue('message');
    $title = $request->getValue('title');
    if ($add_participant_phids) {
      $xactions[] = id(new ConpherenceTransaction())
        ->setTransactionType(
          ConpherenceTransactionType::TYPE_PARTICIPANTS)
        ->setNewValue(array('+' => $add_participant_phids));
    }
    if ($remove_participant_phid) {
      if ($remove_participant_phid != $user->getPHID()) {
        throw new ConduitException('ERR_USAGE_ONLY_SELF_REMOVE');
      }
      $xactions[] = id(new ConpherenceTransaction())
        ->setTransactionType(
          ConpherenceTransactionType::TYPE_PARTICIPANTS)
        ->setNewValue(array('-' => array($remove_participant_phid)));
    }
    if ($title) {
      $xactions[] = id(new ConpherenceTransaction())
        ->setTransactionType(ConpherenceTransactionType::TYPE_TITLE)
        ->setNewValue($title);
    }
    if ($message) {
      $xactions = array_merge(
        $xactions,
        $editor->generateTransactionsFromText($conpherence, $message));
    }

    try {
      $xactions = $editor->applyTransactions($conpherence, $xactions);
    } catch (PhabricatorApplicationTransactionNoEffectException $ex) {
      throw new ConduitException('ERR_USAGE_NO_UPDATES');
    }

    return true;
  }
}
