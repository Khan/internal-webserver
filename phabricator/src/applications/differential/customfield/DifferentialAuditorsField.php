<?php

final class DifferentialAuditorsField
  extends DifferentialStoredCustomField {

  public function getFieldKey() {
    return 'phabricator:auditors';
  }

  public function getFieldName() {
    return pht('Auditors');
  }

  public function getFieldDescription() {
    return pht('Allows commits to trigger audits explicitly.');
  }

  public function getValueForStorage() {
    return json_encode($this->getValue());
  }

  public function setValueFromStorage($value) {
    $this->setValue(phutil_json_decode($value));
    return $this;
  }

  public function shouldAppearInCommitMessage() {
    return true;
  }

  public function shouldAllowEditInCommitMessage() {
    return true;
  }

  public function canDisableField() {
    return false;
  }

  public function getRequiredHandlePHIDsForCommitMessage() {
    return nonempty($this->getValue(), array());
  }

  public function parseCommitMessageValue($value) {
    return $this->parseObjectList(
      $value,
      array(
        PhabricatorPeoplePHIDTypeUser::TYPECONST,
        PhabricatorProjectPHIDTypeProject::TYPECONST,
      ));
  }

  public function renderCommitMessageValue(array $handles) {
    return $this->renderObjectList($handles);
  }


}
