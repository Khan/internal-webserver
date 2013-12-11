<?php

final class PhragmentPHIDTypeSnapshot
  extends PhabricatorPHIDType {

  const TYPECONST = 'PHRS';

  public function getTypeConstant() {
    return self::TYPECONST;
  }

  public function getTypeName() {
    return pht('Snapshot');
  }

  public function newObject() {
    return new PhragmentSnapshot();
  }

  protected function buildQueryForObjects(
    PhabricatorObjectQuery $query,
    array $phids) {

    return id(new PhragmentSnapshotQuery())
      ->withPHIDs($phids);
  }

  public function loadHandles(
    PhabricatorHandleQuery $query,
    array $handles,
    array $objects) {

    $viewer = $query->getViewer();
    foreach ($handles as $phid => $handle) {
      $snapshot = $objects[$phid];

      $handle->setName(pht(
        'Snapshot: %s',
        $snapshot->getName()));
      $handle->setURI($snapshot->getURI());
    }
  }

  public function canLoadNamedObject($name) {
    return false;
  }

}
