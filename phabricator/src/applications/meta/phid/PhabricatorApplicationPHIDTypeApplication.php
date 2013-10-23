<?php

final class PhabricatorApplicationPHIDTypeApplication
  extends PhabricatorPHIDType {

  const TYPECONST = 'APPS';

  public function getTypeConstant() {
    return self::TYPECONST;
  }

  public function getTypeName() {
    return pht('Application');
  }

  public function newObject() {
    return null;
  }

  protected function buildQueryForObjects(
    PhabricatorObjectQuery $query,
    array $phids) {

    return id(new PhabricatorApplicationQuery())
      ->withPHIDs($phids);
  }

  public function loadHandles(
    PhabricatorHandleQuery $query,
    array $handles,
    array $objects) {

    foreach ($handles as $phid => $handle) {
      $application = $objects[$phid];

      $handle->setName($application->getName());
      $handle->setURI($application->getApplicationURI());
    }
  }

  public function canLoadNamedObject($name) {
    return false;
  }

}
