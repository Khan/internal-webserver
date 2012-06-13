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

final class PhabricatorNotificationIndividualController
  extends PhabricatorNotificationController {

  public function processRequest() {
    $request = $this->getRequest();
    $user = $request->getUser();

    $chron_key = $request->getStr('key');
    $story = id(new PhabricatorFeedStoryNotification())
      ->loadOneWhere('userPHID = %s AND chronologicalKey = %s',
                     $user->getPHID(),
                     $chron_key);

    if ($story == null) {
      $json = array( "pertinent" => false );
    } else {
      $json = array(
        "pertinent" => true,
        "primaryObjectPHID" => $story->getPrimaryObjectPHID(),
      );
    }

    return id(new AphrontAjaxResponse())->setContent($json);
  }
}
