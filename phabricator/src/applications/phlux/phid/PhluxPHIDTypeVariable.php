<?php

final class PhluxPHIDTypeVariable extends PhabricatorPHIDType {

  const TYPECONST = 'PVAR';

  public function getTypeConstant() {
    return self::TYPECONST;
  }

  public function getTypeName() {
    return pht('Variable');
  }

  public function newObject() {
    return new PhluxVariable();
  }

  protected function buildQueryForObjects(
    PhabricatorObjectQuery $query,
    array $phids) {

    return id(new PhluxVariableQuery())
      ->withPHIDs($phids);
  }

  public function loadHandles(
    PhabricatorHandleQuery $query,
    array $handles,
    array $objects) {

    foreach ($handles as $phid => $handle) {
      $variable = $objects[$phid];

      $key = $variable->getVariableKey();

      $handle->setName($key);
      $handle->setFullName(pht('Variable "%s"', $key));
      $handle->setURI("/phlux/view/{$key}/");
    }
  }

}
