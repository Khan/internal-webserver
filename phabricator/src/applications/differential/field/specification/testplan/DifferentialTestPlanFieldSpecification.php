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

final class DifferentialTestPlanFieldSpecification
  extends DifferentialFieldSpecification {

  private $plan = '';

  // NOTE: This means "uninitialized".
  private $error = false;

  public function shouldAppearOnEdit() {
    return true;
  }

  protected function didSetRevision() {
    $this->plan = (string)$this->getRevision()->getTestPlan();
  }

  public function setValueFromRequest(AphrontRequest $request) {
    $this->plan = $request->getStr('testplan');
    $this->error = null;
    return $this;
  }

  public function renderEditControl() {
    if ($this->error === false) {
      if ($this->isRequired()) {
        $this->error = true;
      } else {
        $this->error = null;
      }
    }

    return id(new AphrontFormTextAreaControl())
      ->setLabel('Test Plan')
      ->setName('testplan')
      ->setValue($this->plan)
      ->setError($this->error);
  }

  public function willWriteRevision(DifferentialRevisionEditor $editor) {
    $this->getRevision()->setTestPlan($this->plan);
  }

  public function validateField() {
    if ($this->isRequired()) {
      if (!strlen($this->plan)) {
        $this->error = 'Required';
        throw new DifferentialFieldValidationException(
          "You must provide a test plan.");
      }
    }
  }

  public function shouldAppearOnCommitMessage() {
    return true;
  }

  public function getCommitMessageKey() {
    return 'testPlan';
  }

  public function setValueFromParsedCommitMessage($value) {
    $this->plan = (string)$value;
    return $this;
  }

  public function shouldOverwriteWhenCommitMessageIsEdited() {
    return true;
  }

  public function renderLabelForCommitMessage() {
    return 'Test Plan';
  }

  public function getSupportedCommitMessageLabels() {
    return array(
      'Test Plan',
      'Testplan',
      'Tested',
      'Tests',
    );
  }


  public function renderValueForCommitMessage($is_edit) {
    return $this->plan;
  }

  public function parseValueFromCommitMessage($value) {
    return $value;
  }

  private function isRequired() {
    return PhabricatorEnv::getEnvConfig('differential.require-test-plan-field');
  }

}
