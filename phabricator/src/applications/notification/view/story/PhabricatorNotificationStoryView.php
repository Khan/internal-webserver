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

final class PhabricatorNotificationStoryView
extends PhabricatorNotificationView {

  private $title;
  private $phid;
  private $epoch;
  private $viewed;

  public function setTitle($title) {
    $this->title = $title;
    return $this;
  }

  public function setEpoch($epoch) {
    $this->epoch = $epoch;
    return $this;
  }

  public function setViewed($viewed) {
    $this->viewed = $viewed;
  }

  public function render() {

    $title = $this->title;
    if (!$this->viewed) {
      $title = '<b>'.$title.'</b>';
    }

    $head = phutil_render_tag(
      'div',
      array(
        'class' => 'phabricator-notification-story-head',
      ),
      nonempty($title, 'Untitled Story'));


    return phutil_render_tag(
      'div',
      array(
        'class' =>
          'phabricator-notification '.
          'phabricator-notification-story-one-line'),
      $head);
  }

}
