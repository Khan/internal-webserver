<?php

/**
 * @group file
 */
final class FileReplyHandler extends PhabricatorMailReplyHandler {

  public function validateMailReceiver($mail_receiver) {
    if (!($mail_receiver instanceof PhabricatorFile)) {
      throw new Exception('Mail receiver is not a PhabricatorFile.');
    }
  }

  public function getPrivateReplyHandlerEmailAddress(
    PhabricatorObjectHandle $handle) {
    return $this->getDefaultPrivateReplyHandlerEmailAddress($handle, 'F');
  }

  public function getPublicReplyHandlerEmailAddress() {
    return $this->getDefaultPublicReplyHandlerEmailAddress('F');
  }

  public function getReplyHandlerInstructions() {
    if ($this->supportsReplies()) {
      return pht('Reply to comment or !unsubscribe.');
    } else {
      return null;
    }
  }

  protected function receiveEmail(PhabricatorMetaMTAReceivedMail $mail) {
    $actor = $this->getActor();
    $file = $this->getMailReceiver();

    $body = $mail->getCleanTextBody();
    $body = trim($body);
    $body = $this->enhanceBodyWithAttachments($body, $mail->getAttachments());

    $content_source = PhabricatorContentSource::newForSource(
      PhabricatorContentSource::SOURCE_EMAIL,
      array(
        'id' => $mail->getID(),
      ));

    $lines = explode("\n", trim($body));
    $first_line = head($lines);

    $xactions = array();
    $command = null;
    $matches = null;
    if (preg_match('/^!(\w+)/', $first_line, $matches)) {
      $lines = array_slice($lines, 1);
      $body = implode("\n", $lines);
      $body = trim($body);

      $command = $matches[1];
    }

    switch ($command) {
      case 'unsubscribe':
        $xaction = id(new PhabricatorFileTransaction())
          ->setTransactionType(PhabricatorTransactions::TYPE_SUBSCRIBERS)
          ->setNewValue(array('-' => array($actor->getPHID())));
        $xactions[] = $xaction;
        break;
    }

    $xactions[] = id(new PhabricatorFileTransaction())
      ->setTransactionType(PhabricatorTransactions::TYPE_COMMENT)
      ->attachComment(
       id(new PhabricatorFileTransactionComment())
        ->setContent($body));

    $editor = id(new PhabricatorFileEditor())
      ->setActor($actor)
      ->setContentSource($content_source)
      ->setContinueOnNoEffect(true)
      ->setIsPreview(false);

    try {
      $xactions = $editor->applyTransactions($file, $xactions);
    } catch (PhabricatorApplicationTransactionNoEffectException $ex) {
      // just do nothing, though unclear why you're sending a blank email
      return true;
    }

    $head_xaction = head($xactions);
    return $head_xaction->getID();

  }

}
