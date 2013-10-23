<?php

final class HarbormasterPHIDTypeBuild extends PhabricatorPHIDType {

  const TYPECONST = 'HMBD';

  public function getTypeConstant() {
    return self::TYPECONST;
  }

  public function getTypeName() {
    return pht('Build');
  }

  public function newObject() {
    return new HarbormasterBuild();
  }

  protected function buildQueryForObjects(
    PhabricatorObjectQuery $query,
    array $phids) {

    return id(new HarbormasterBuildQuery())
      ->withPHIDs($phids);
  }

  public function loadHandles(
    PhabricatorHandleQuery $query,
    array $handles,
    array $objects) {

    foreach ($handles as $phid => $handle) {
      $build_plan = $objects[$phid];
    }
  }

}
