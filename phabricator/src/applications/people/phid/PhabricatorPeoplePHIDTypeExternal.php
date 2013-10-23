<?php

final class PhabricatorPeoplePHIDTypeExternal extends PhabricatorPHIDType {

  const TYPECONST = 'XUSR';

  public function getTypeConstant() {
    return self::TYPECONST;
  }

  public function getTypeName() {
    return pht('External Account');
  }

  public function newObject() {
    return new PhabricatorExternalAccount();
  }

  protected function buildQueryForObjects(
    PhabricatorObjectQuery $query,
    array $phids) {

    return id(new PhabricatorExternalAccountQuery())
      ->withPHIDs($phids);
  }

  public function loadHandles(
    PhabricatorHandleQuery $query,
    array $handles,
    array $objects) {

    foreach ($handles as $phid => $handle) {
      $account = $objects[$phid];

      $display_name = $account->getDisplayName();
      $handle->setName($display_name);
    }
  }

  public function canLoadNamedObject($name) {
    return false;
  }

}
