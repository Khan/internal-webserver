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

final class PhabricatorFeedStoryManiphest
  extends PhabricatorFeedStory {

  public function getRequiredHandlePHIDs() {
    $data = $this->getStoryData();
    return array_filter(
      array(
        $this->getStoryData()->getAuthorPHID(),
        $data->getValue('taskPHID'),
        $data->getValue('ownerPHID'),
            ));
  }

  public function getRequiredObjectPHIDs() {
    return array(
      $this->getStoryData()->getAuthorPHID(),
                 );
  }

  public function renderView() {
    $data = $this->getStoryData();

    $view = new PhabricatorFeedStoryView();

    $line = $this->getLineForData($data);
    $view->setTitle($line);
    $view->setEpoch($data->getEpoch());

    $action = $data->getValue('action');
    switch ($action) {
      case ManiphestAction::ACTION_CREATE:
        $full_size = true;
        break;
      default:
        $full_size = false;
        break;
    }

    if ($full_size) {
      $view->setImage($this->getHandle($data->getAuthorPHID())->getImageURI());
      $content = $this->renderSummary($data->getValue('description'));
      $view->appendChild($content);
    } else {
      $view->setOneLineStory(true);
    }

    return $view;
  }

  public function renderNotificationView() {
    $data = $this->getStoryData();

    $view = new PhabricatorNotificationStoryView();

    $view->setTitle($this->getLineForData($data));
    $view->setEpoch($data->getEpoch());
    $view->setViewed($this->getHasViewed());

    return $view;
  }

  private function getLineForData($data) {
    $actor_phid = $data->getAuthorPHID();
    $owner_phid = $data->getValue('ownerPHID');
    $task_phid = $data->getValue('taskPHID');
    $action = $data->getValue('action');
    $description = $data->getValue('description');
    $comments = phutil_escape_html(
      phutil_utf8_shorten(
        $data->getValue('comments'),
        140));

    $actor_link = $this->linkTo($actor_phid);
    $task_link = $this->linkTo($task_phid);
    $owner_link = $this->linkTo($owner_phid);

    $verb = ManiphestAction::getActionPastTenseVerb($action);

    if (($action == ManiphestAction::ACTION_ASSIGN
        || $action == ManiphestAction::ACTION_REASSIGN)
        && !$owner_phid) {
      //double assignment since the action is diff in this case
      $verb = $action = 'placed up for grabs';
    }
    $one_line = "{$actor_link} {$verb} {$task_link}";

    switch ($action) {
      case ManiphestAction::ACTION_ASSIGN:
      case ManiphestAction::ACTION_REASSIGN:
        $one_line .= " to {$owner_link}";
        break;
      case ManiphestAction::ACTION_DESCRIPTION:
        $one_line .= " to {$description}";
        break;
    }

    if ($comments) {
      $one_line .= " \"{$comments}\"";
    }

    return $one_line;
  }
}
