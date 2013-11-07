<?php

final class NuancePHIDTypeItem
  extends PhabricatorPHIDType {

  const TYPECONST = 'NUAI';

  public function getTypeConstant() {
    return self::TYPECONST;
  }

  public function getTypeName() {
    return pht('Item');
  }

  public function newObject() {
    return new NuanceItem();
  }

  protected function buildQueryForObjects(
    PhabricatorObjectQuery $query,
    array $phids) {

    return id(new NuanceItemQuery())
      ->withPHIDs($phids);
  }

  public function loadHandles(
    PhabricatorHandleQuery $query,
    array $handles,
    array $objects) {

    $viewer = $query->getViewer();
    foreach ($handles as $phid => $handle) {
      $item = $objects[$phid];

      $handle->setName($item->getLabel($viewer));
      $handle->setURI($item->getURI());
    }
  }

  public function canLoadNamedObject($name) {
    return false;
  }

}
