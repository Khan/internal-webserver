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

final class PhabricatorFeedStoryDifferential extends PhabricatorFeedStory {

  public function getRequiredHandlePHIDs() {
    $data = $this->getStoryData();
    return array(
      $this->getStoryData()->getAuthorPHID(),
      $data->getValue('revision_phid'),
      $data->getValue('revision_author_phid'),
    );
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
      case DifferentialAction::ACTION_CREATE:
      case DifferentialAction::ACTION_CLOSE:
        $full_size = true;
        break;
      default:
        $full_size = false;
        break;
    }

    if ($full_size) {
      $view->setImage($this->getHandle($data->getAuthorPHID())->getImageURI());
      $content = $this->renderSummary($data->getValue('feedback_content'));
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
    $owner_phid = $data->getValue('revision_author_phid');
    $revision_phid = $data->getValue('revision_phid');
    $action = $data->getValue('action');

    $actor_link = $this->linkTo($actor_phid);
    $revision_link = $this->linkTo($revision_phid);
    $owner_link = $this->linkTo($owner_phid);

    $verb = DifferentialAction::getActionPastTenseVerb($action);

    $one_line = "{$actor_link} {$verb} revision {$revision_link}";

    return $one_line;
  }
}
