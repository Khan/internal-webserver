<?php

final class PhabricatorMetaMTASendGridReceiveController
  extends PhabricatorMetaMTAController {

  public function shouldRequireLogin() {
    return false;
  }

  public function shouldRequireAdmin() {
    return false;
  }

  public function processRequest() {

    // No CSRF for SendGrid.
    $unguarded = AphrontWriteGuard::beginScopedUnguardedWrites();

    $request = $this->getRequest();
    $user = $request->getUser();

    $raw_headers = $request->getStr('headers');
    $raw_headers = explode("\n", rtrim($raw_headers));
    $raw_dict = array();
    foreach (array_filter($raw_headers) as $header) {
      list($name, $value) = explode(':', $header, 2);
      $raw_dict[$name] = ltrim($value);
    }

    $headers = array(
      'to'      => $request->getStr('to'),
      'from'    => $request->getStr('from'),
      'subject' => $request->getStr('subject'),
    ) + $raw_dict;

    $received = new PhabricatorMetaMTAReceivedMail();
    $received->setHeaders($headers);
    $received->setBodies(array(
      'text' => $request->getStr('text'),
      'html' => $request->getStr('from'),
    ));

    $file_phids = array();
    foreach ($_FILES as $file_raw) {
      try {
        $file = PhabricatorFile::newFromPHPUpload(
          $file_raw,
          array(
            'authorPHID' => $user->getPHID(),
          ));
        $file_phids[] = $file->getPHID();
      } catch (Exception $ex) {
        phlog($ex);
      }
    }
    $received->setAttachments($file_phids);
    $received->save();

    $received->processReceivedMail();

    $response = new AphrontWebpageResponse();
    $response->setContent(pht("Got it! Thanks, SendGrid!\n"));
    return $response;
  }

}
