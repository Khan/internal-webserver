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

/**
 * @group conduit
 */
final class ConduitAPI_feed_publish_Method
  extends ConduitAPIMethod {

  public function getMethodStatus() {
    return self::METHOD_STATUS_UNSTABLE;
  }

  public function getMethodDescription() {
    return "Publish a story to the feed.";
  }

  public function defineParamTypes() {
    return array(
      'type' => 'required string',
      'data' => 'required dict',
      'time' => 'optional int',
    );
  }

  public function defineErrorTypes() {
    return array(
    );
  }

  public function defineReturnType() {
    return 'nonempty phid';
  }

  protected function execute(ConduitAPIRequest $request) {
    $type = $request->getValue('type');
    $data = $request->getValue('data');
    $time = $request->getValue('time');

    $author_phid = $request->getUser()->getPHID();
    $phids = array($author_phid);

    $publisher = new PhabricatorFeedStoryPublisher();
    $publisher->setStoryType($type);
    $publisher->setStoryData($data);
    $publisher->setStoryTime($time);
    $publisher->setRelatedPHIDs($phids);
    $publisher->setStoryAuthorPHID($author_phid);

    $data = $publisher->publish();

    return $data->getPHID();
  }

}
