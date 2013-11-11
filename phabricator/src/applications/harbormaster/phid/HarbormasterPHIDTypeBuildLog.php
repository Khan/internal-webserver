<?php

final class HarbormasterPHIDTypeBuildLog extends PhabricatorPHIDType {

  const TYPECONST = 'HMCL';

  public function getTypeConstant() {
    return self::TYPECONST;
  }

  public function getTypeName() {
    return pht('Build Log');
  }

  public function newObject() {
    return new HarbormasterBuildLog();
  }

  protected function buildQueryForObjects(
    PhabricatorObjectQuery $query,
    array $phids) {

    return id(new HarbormasterBuildLogQuery())
      ->withPHIDs($phids);
  }

  public function loadHandles(
    PhabricatorHandleQuery $query,
    array $handles,
    array $objects) {

    foreach ($handles as $phid => $handle) {
      $build_log = $objects[$phid];
    }
  }

}
