<?php

final class PhabricatorUserTitleField
  extends PhabricatorUserCustomField {

  private $value;

  public function getFieldKey() {
    return 'user:title';
  }

  public function getFieldName() {
    return pht('Title');
  }

  public function getFieldDescription() {
    return pht('User title, like "CEO" or "Assistant to the Manager".');
  }

  protected function didSetObject(PhabricatorCustomFieldInterface $object) {
    $this->value = $object->loadUserProfile()->getTitle();
  }

  public function getOldValueForApplicationTransactions() {
    return $this->getObject()->loadUserProfile()->getTitle();
  }

  public function getNewValueForApplicationTransactions() {
    return $this->value;
  }

  public function applyApplicationTransactionInternalEffects(
    PhabricatorApplicationTransaction $xaction) {
    $this->getObject()->loadUserProfile()->setTitle($xaction->getNewValue());
  }

  public function setValueFromRequest(AphrontRequest $request) {
    $this->value = $request->getStr($this->getFieldKey());
  }

  public function renderEditControl() {
    return id(new AphrontFormTextControl())
      ->setName($this->getFieldKey())
      ->setValue($this->value)
      ->setLabel($this->getFieldName())
      ->setCaption(pht('Serious business title.'));
  }

}
