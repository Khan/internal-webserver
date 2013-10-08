<?php

final class PhabricatorManiphestTaskTestDataGenerator
  extends PhabricatorTestDataGenerator {

  public function generate() {
    $authorPHID = $this->loadPhabrictorUserPHID();
    $author = id(new PhabricatorUser())
          ->loadOneWhere('phid = %s', $authorPHID);
    $task = id(new ManiphestTask())
      ->setSubPriority($this->generateTaskSubPriority())
      ->setAuthorPHID($authorPHID)
      ->setTitle($this->generateTitle())
      ->setStatus(ManiphestTaskStatus::STATUS_OPEN);

    $content_source = PhabricatorContentSource::newForSource(
      PhabricatorContentSource::SOURCE_UNKNOWN,
      array());

    $template = new ManiphestTransaction();
    // Accumulate Transactions
    $changes = array();
    $changes[ManiphestTransaction::TYPE_TITLE] =
      $this->generateTitle();
    $changes[ManiphestTransaction::TYPE_DESCRIPTION] =
      $this->generateDescription();
    $changes[ManiphestTransaction::TYPE_OWNER] =
      $this->loadOwnerPHID();
    $changes[ManiphestTransaction::TYPE_STATUS] =
      $this->generateTaskStatus();
    $changes[ManiphestTransaction::TYPE_PRIORITY] =
      $this->generateTaskPriority();
    $changes[ManiphestTransaction::TYPE_CCS] =
      $this->getCCPHIDs();
    $changes[ManiphestTransaction::TYPE_PROJECTS] =
      $this->getProjectPHIDs();
    $transactions = array();
    foreach ($changes as $type => $value) {
      $transaction = clone $template;
      $transaction->setTransactionType($type);
      $transaction->setNewValue($value);
      $transactions[] = $transaction;
    }

    // Apply Transactions
    $editor = id(new ManiphestTransactionEditorPro())
      ->setActor($author)
      ->setContentSource($content_source)
      ->setContinueOnNoEffect(true)
      ->setContinueOnMissingFields(true)
      ->applyTransactions($task, $transactions);
    return $task;
  }

  public function getCCPHIDs() {
    $ccs = array();
    for ($i = 0; $i < rand(1, 4);$i++) {
      $ccs[] = $this->loadPhabrictorUserPHID();
    }
    return $ccs;
  }

  public function getProjectPHIDs() {
    $projects = array();
    for ($i = 0; $i < rand(1, 4);$i++) {
      $project = $this->loadOneRandom("PhabricatorProject");
      if ($project) {
        $projects[] = $project->getPHID();
      }
    }
    return $projects;
  }

  public function loadOwnerPHID() {
    if (rand(0, 3) == 0) {
      return null;
    } else {
      return $this->loadPhabrictorUserPHID();
    }
  }

  public function generateTitle() {
    return id(new PhutilLipsumContextFreeGrammar())
      ->generate();
  }

  public function generateDescription() {
    return id(new PhutilLipsumContextFreeGrammar())
      ->generateSeveral(rand(30, 40));
  }

  public function generateTaskPriority() {
    return array_rand(ManiphestTaskPriority::getTaskPriorityMap());
  }

  public function generateTaskSubPriority() {
    return rand(2 << 16, 2 << 32);
  }

  public function generateTaskStatus() {
    $statuses = array_keys(ManiphestTaskStatus::getTaskStatusMap());
    // Make sure 4/5th of all generated Tasks are open
    $random = rand(0, 4);
    if ($random != 0) {
      return ManiphestTaskStatus::STATUS_OPEN;
    } else {
      return array_rand($statuses);
    }
  }


}
