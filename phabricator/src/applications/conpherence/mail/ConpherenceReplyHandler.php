<?php

/**
 * @group conpherence
 */
final class ConpherenceReplyHandler extends PhabricatorMailReplyHandler {

  public function validateMailReceiver($mail_receiver) {
    if (!($mail_receiver instanceof ConpherenceThread)) {
      throw new Exception("Mail receiver is not a ConpherenceThread!");
    }
  }

  public function getPrivateReplyHandlerEmailAddress(
    PhabricatorObjectHandle $handle) {
    return $this->getDefaultPrivateReplyHandlerEmailAddress($handle, 'E');
  }

  public function getPublicReplyHandlerEmailAddress() {
    return $this->getDefaultPublicReplyHandlerEmailAddress('E');
  }

  public function getReplyHandlerInstructions() {
    if ($this->supportsReplies()) {
      return pht('Reply to comment and attach files.');
    } else {
      return null;
    }
  }

  protected function receiveEmail(PhabricatorMetaMTAReceivedMail $mail) {
    $conpherence = $this->getMailReceiver();
    $user = $this->getActor();
    if (!$conpherence->getPHID()) {
      $conpherence
        ->attachParticipants(array())
        ->attachFilePHIDs(array());
    } else {
      $edge_type = PhabricatorEdgeConfig::TYPE_OBJECT_HAS_FILE;
      $file_phids = PhabricatorEdgeQuery::loadDestinationPHIDs(
        $conpherence->getPHID(),
        $edge_type
      );
      $conpherence->attachFilePHIDs($file_phids);
      $participants = id(new ConpherenceParticipant())
        ->loadAllWhere('conpherencePHID = %s', $conpherence->getPHID());
      $participants = mpull($participants, null, 'getParticipantPHID');
      $conpherence->attachParticipants($participants);
    }

    $content_source = PhabricatorContentSource::newForSource(
      PhabricatorContentSource::SOURCE_EMAIL,
      array(
        'id' => $mail->getID(),
      ));

    $editor = id(new ConpherenceEditor())
      ->setActor($user)
      ->setContentSource($content_source)
      ->setParentMessageID($mail->getMessageID());

    $body = $mail->getCleanTextBody();
    $body = trim($body);
    $file_phids = $mail->getAttachments();
    $body = $this->enhanceBodyWithAttachments(
      $body,
      $file_phids,
      '{F%d}'
    );
    $xactions = $editor->generateTransactionsFromText(
      $conpherence,
      $body
    );

    $editor->applyTransactions($conpherence, $xactions);

    return null;
  }

}
