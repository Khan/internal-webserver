<?php

/*
 * Copyright 2012 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

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
    $response->setContent("Got it! Thanks, SendGrid!\n");
    return $response;
  }

}
