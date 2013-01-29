<?php

/**
 * @group conpherence
 */
final class ConpherenceEditor extends PhabricatorApplicationTransactionEditor {

  public function generateTransactionsFromText(
    ConpherenceThread $conpherence,
    $text) {

    $files = array();
    $file_phids =
      PhabricatorMarkupEngine::extractFilePHIDsFromEmbeddedFiles(
        array($text)
      );
    // Since these are extracted from text, we might be re-including the
    // same file -- e.g. a mock under discussion. Filter files we
    // already have.
    $existing_file_phids = $conpherence->getFilePHIDs();
    $file_phids = array_diff($file_phids, $existing_file_phids);
    if ($file_phids) {
      $files = id(new PhabricatorFileQuery())
        ->setViewer($this->getActor())
        ->withPHIDs($file_phids)
        ->execute();
    }
    $xactions = array();
    if ($files) {
      $xactions[] = id(new ConpherenceTransaction())
        ->setTransactionType(ConpherenceTransactionType::TYPE_FILES)
        ->setNewValue(array('+' => mpull($files, 'getPHID')));
    }
    $xactions[] = id(new ConpherenceTransaction())
      ->setTransactionType(PhabricatorTransactions::TYPE_COMMENT)
      ->attachComment(
        id(new ConpherenceTransactionComment())
        ->setContent($text)
        ->setConpherencePHID($conpherence->getPHID())
      );
    return $xactions;
  }

  public function getTransactionTypes() {
    $types = parent::getTransactionTypes();

    $types[] = PhabricatorTransactions::TYPE_COMMENT;

    $types[] = ConpherenceTransactionType::TYPE_TITLE;
    $types[] = ConpherenceTransactionType::TYPE_PICTURE;
    $types[] = ConpherenceTransactionType::TYPE_PARTICIPANTS;
    $types[] = ConpherenceTransactionType::TYPE_FILES;

    return $types;
  }

  protected function getCustomTransactionOldValue(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {

    switch ($xaction->getTransactionType()) {
      case ConpherenceTransactionType::TYPE_TITLE:
        return $object->getTitle();
      case ConpherenceTransactionType::TYPE_PICTURE:
        return $object->getImagePHID();
      case ConpherenceTransactionType::TYPE_PARTICIPANTS:
        return $object->getParticipantPHIDs();
      case ConpherenceTransactionType::TYPE_FILES:
        return $object->getFilePHIDs();
    }
  }

  protected function getCustomTransactionNewValue(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {

    switch ($xaction->getTransactionType()) {
      case ConpherenceTransactionType::TYPE_TITLE:
      case ConpherenceTransactionType::TYPE_PICTURE:
        return $xaction->getNewValue();
      case ConpherenceTransactionType::TYPE_PARTICIPANTS:
      case ConpherenceTransactionType::TYPE_FILES:
        return $this->getPHIDTransactionNewValue($xaction);
    }
  }

  protected function applyCustomInternalTransaction(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {

    switch ($xaction->getTransactionType()) {
      case ConpherenceTransactionType::TYPE_TITLE:
        $object->setTitle($xaction->getNewValue());
        break;
      case ConpherenceTransactionType::TYPE_PICTURE:
        $object->setImagePHID($xaction->getNewValue());
        break;
    }
  }

  /**
   * For now this only supports adding more files and participants.
   */
  protected function applyCustomExternalTransaction(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {

    switch ($xaction->getTransactionType()) {
      case ConpherenceTransactionType::TYPE_FILES:
        $editor = id(new PhabricatorEdgeEditor())
          ->setActor($this->getActor());
        $edge_type = PhabricatorEdgeConfig::TYPE_OBJECT_HAS_FILE;
        foreach ($xaction->getNewValue() as $file_phid) {
          $editor->addEdge(
            $object->getPHID(),
            $edge_type,
            $file_phid
          );
        }
        $editor->save();
        break;
      case ConpherenceTransactionType::TYPE_PARTICIPANTS:
        foreach ($xaction->getNewValue() as $participant) {
          if ($participant == $this->getActor()->getPHID()) {
            $status = ConpherenceParticipationStatus::UP_TO_DATE;
          } else {
            $status = ConpherenceParticipationStatus::BEHIND;
          }
          id(new ConpherenceParticipant())
            ->setConpherencePHID($object->getPHID())
            ->setParticipantPHID($participant)
            ->setParticipationStatus($status)
            ->setDateTouched(time())
            ->setBehindTransactionPHID($xaction->getPHID())
            ->save();
        }
        break;
     }
  }

  protected function mergeTransactions(
    PhabricatorApplicationTransaction $u,
    PhabricatorApplicationTransaction $v) {

    $type = $u->getTransactionType();
    switch ($type) {
      case ConpherenceTransactionType::TYPE_TITLE:
      case ConpherenceTransactionType::TYPE_PICTURE:
        return $v;
      case ConpherenceTransactionType::TYPE_FILES:
      case ConpherenceTransactionType::TYPE_PARTICIPANTS:
        return $this->mergePHIDTransactions($u, $v);
    }

    return parent::mergeTransactions($u, $v);
  }

  protected function supportsMail() {
    return true;
  }

  protected function buildReplyHandler(PhabricatorLiskDAO $object) {
    return id(new ConpherenceReplyHandler())
      ->setActor($this->getActor())
      ->setMailReceiver($object);
  }

  protected function buildMailTemplate(PhabricatorLiskDAO $object) {
    $id = $object->getID();
    $title = $object->getTitle();
    if (!$title) {
      $title = pht(
        '%s sent you a message.',
        $this->getActor()->getUserName()
      );
    }
    $phid = $object->getPHID();

    return id(new PhabricatorMetaMTAMail())
      ->setSubject("E{$id}: {$title}")
      ->addHeader('Thread-Topic', "E{$id}: {$phid}");
  }

  protected function getMailTo(PhabricatorLiskDAO $object) {
    $participants = $object->getParticipants();
    return array_keys($participants);
  }

  protected function getMailCC(PhabricatorLiskDAO $object) {
    return array();
  }

  protected function buildMailBody(
    PhabricatorLiskDAO $object,
    array $xactions) {

    $body = parent::buildMailBody($object, $xactions);
    $body->addTextSection(
      pht('CONPHERENCE DETAIL'),
      PhabricatorEnv::getProductionURI('/conpherence/'.$object->getID().'/'));

    return $body;
  }

  protected function getMailSubjectPrefix() {
    return PhabricatorEnv::getEnvConfig('metamta.conpherence.subject-prefix');
  }

  protected function supportsFeed() {
    return false;
  }

  protected function supportsSearch() {
    return false;
  }

}
