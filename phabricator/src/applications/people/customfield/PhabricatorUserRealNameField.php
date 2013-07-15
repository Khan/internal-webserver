<?php

final class PhabricatorUserRealNameField
  extends PhabricatorUserCustomField {

  private $value;

  public function getFieldKey() {
    return 'user:realname';
  }

  public function getFieldName() {
    return pht('Real Name');
  }

  public function getFieldDescription() {
    return pht('Stores the real name of the user, like "Abraham Lincoln".');
  }

  public function canDisableField() {
    return false;
  }

  public function shouldAppearInApplicationTransactions() {
    return true;
  }

  public function shouldAppearInEditView() {
    return true;
  }

  protected function didSetObject(PhabricatorCustomFieldInterface $object) {
    $this->value = $object->getRealName();
  }

  public function getOldValueForApplicationTransactions() {
    return $this->getObject()->getRealName();
  }

  public function getNewValueForApplicationTransactions() {
    if (!$this->isEditable()) {
      return $this->getObject()->getRealName();
    }
    return $this->value;
  }

  public function applyApplicationTransactionInternalEffects(
    PhabricatorApplicationTransaction $xaction) {
    $this->getObject()->setRealName($xaction->getNewValue());
  }

  public function setValueFromRequest(AphrontRequest $request) {
    $this->value = $request->getStr($this->getFieldKey());
  }

  public function renderEditControl() {
    return id(new AphrontFormTextControl())
      ->setName($this->getFieldKey())
      ->setValue($this->value)
      ->setLabel($this->getFieldName())
      ->setDisabled(!$this->isEditable());
  }

  private function isEditable() {
    return PhabricatorEnv::getEnvConfig('account.editable');
  }

}
