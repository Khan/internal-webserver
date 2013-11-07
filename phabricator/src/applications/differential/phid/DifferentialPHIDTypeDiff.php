<?php

final class DifferentialPHIDTypeDiff extends PhabricatorPHIDType {

  const TYPECONST = 'DIFF';

  public function getTypeConstant() {
    return self::TYPECONST;
  }

  public function getTypeName() {
    return pht('Differential Diff');
  }

  public function newObject() {
    return new DifferentialDiff();
  }

  protected function buildQueryForObjects(
    PhabricatorObjectQuery $query,
    array $phids) {

    return id(new DifferentialDiffQuery())
      ->withPHIDs($phids);
  }

  public function loadHandles(
    PhabricatorHandleQuery $query,
    array $handles,
    array $objects) {

    foreach ($handles as $phid => $handle) {
      $diff = $objects[$phid];

      $id = $diff->getID();

      $handle->setName(pht('Diff %d', $id));
    }
  }

  public function canLoadNamedObject($name) {
    return false;
  }

}
