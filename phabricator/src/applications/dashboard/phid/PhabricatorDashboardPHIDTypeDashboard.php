<?php

final class PhabricatorDashboardPHIDTypeDashboard extends PhabricatorPHIDType {

  const TYPECONST = 'DSHB';

  public function getTypeConstant() {
    return self::TYPECONST;
  }

  public function getTypeName() {
    return pht('Dashboard');
  }

  public function newObject() {
    return new PhabricatorDashboard();
  }

  protected function buildQueryForObjects(
    PhabricatorObjectQuery $query,
    array $phids) {

    return id(new PhabricatorDashboardQuery())
      ->withPHIDs($phids);
  }

  public function loadHandles(
    PhabricatorHandleQuery $query,
    array $handles,
    array $objects) {

    foreach ($handles as $phid => $handle) {
      $dashboard = $objects[$phid];

      $id = $dashboard->getID();

      $handle->setName($dashboard->getName());
      $handle->setURI("/dashboard/view/{$id}/");
    }
  }

}
