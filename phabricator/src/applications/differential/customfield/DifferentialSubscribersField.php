<?php

final class DifferentialSubscribersField
  extends DifferentialCoreCustomField {

  public function getFieldKey() {
    return 'differential:subscribers';
  }

  public function getFieldKeyForConduit() {
    return 'ccPHIDs';
  }

  public function getFieldName() {
    return pht('Subscribers');
  }

  public function getFieldDescription() {
    return pht('Manage subscribers.');
  }

  protected function readValueFromRevision(
    DifferentialRevision $revision) {
    if (!$revision->getPHID()) {
      return array();
    }

    return PhabricatorSubscribersQuery::loadSubscribersForPHID(
      $revision->getPHID());
  }

  public function getNewValueForApplicationTransactions() {
    return array('=' => $this->getValue());
  }

  public function readValueFromRequest(AphrontRequest $request) {
    $this->setValue($request->getArr($this->getFieldKey()));
  }

  public function getRequiredHandlePHIDsForEdit() {
    return $this->getValue();
  }

  public function renderEditControl(array $handles) {
    return id(new AphrontFormTokenizerControl())
      ->setName($this->getFieldKey())
      ->setDatasource('/typeahead/common/mailable/')
      ->setValue($handles)
      ->setError($this->getFieldError())
      ->setLabel($this->getFieldName());
  }

  public function getApplicationTransactionType() {
    return PhabricatorTransactions::TYPE_SUBSCRIBERS;
  }

  public function shouldAppearInCommitMessage() {
    return true;
  }

  public function shouldAllowEditInCommitMessage() {
    return true;
  }

  public function shouldAppearInCommitMessageTemplate() {
    return true;
  }

  public function getCommitMessageLabels() {
    return array(
      'CC',
      'CCs',
      'Subscriber',
      'Subscribers',
    );
  }

  public function parseValueFromCommitMessage($value) {
    return $this->parseObjectList(
      $value,
      array(
        PhabricatorPeoplePHIDTypeUser::TYPECONST,
        PhabricatorProjectPHIDTypeProject::TYPECONST,
        PhabricatorMailingListPHIDTypeList::TYPECONST,
      ));
  }

  public function getRequiredHandlePHIDsForCommitMessage() {
    return $this->getValue();
  }

  public function renderCommitMessageValue(array $handles) {
    return $this->renderObjectList($handles);
  }

}
