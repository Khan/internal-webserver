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

final class DifferentialRevisionDetailView extends AphrontView {

  private $revision;
  private $actions;
  private $user;
  private $auxiliaryFields = array();

  public function setRevision($revision) {
    $this->revision = $revision;
    return $this;
  }

  public function setActions(array $actions) {
    $this->actions = $actions;
    return $this;
  }

  public function setUser($user) {
    $this->user = $user;
    return $this;
  }

  public function setAuxiliaryFields(array $fields) {
    assert_instances_of($fields, 'DifferentialFieldSpecification');
    $this->auxiliaryFields = $fields;
    return $this;
  }

  public function render() {

    require_celerity_resource('differential-core-view-css');

    $revision = $this->revision;

    $dict = array();
    foreach ($this->auxiliaryFields as $field) {
      $value = $field->renderValueForRevisionView();
      if (strlen($value)) {
        $label = rtrim($field->renderLabelForRevisionView(), ':');
        $dict[$label] = $value;
      }
    }

    $actions = array();
    foreach ($this->actions as $action) {
      $obj = new AphrontHeadsupActionView();
      $obj->setName($action['name']);
      $obj->setURI(idx($action, 'href'));
      $obj->setWorkflow(idx($action, 'sigil') == 'workflow');
      $obj->setClass(idx($action, 'class'));
      $obj->setInstant(idx($action, 'instant'));
      $obj->setUser($this->user);
      $actions[] = $obj;
    }

    $action_list = new AphrontHeadsupActionListView();
    $action_list->setActions($actions);

    $action_panel = new AphrontHeadsupView();
    $action_panel->setActionList($action_list);
    $action_panel->setHasKeyboardShortcuts(true);
    $action_panel->setProperties($dict);

    $action_panel->setObjectName('D'.$revision->getID());
    $action_panel->setHeader($revision->getTitle());

    return $action_panel->render();
  }
}
