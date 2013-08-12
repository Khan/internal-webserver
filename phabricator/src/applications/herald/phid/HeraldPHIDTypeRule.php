<?php

final class HeraldPHIDTypeRule extends PhabricatorPHIDType {

  const TYPECONST = 'HRUL';

  public function getTypeConstant() {
    return self::TYPECONST;
  }

  public function getTypeName() {
    return pht('Herald Rule');
  }

  public function newObject() {
    return new HeraldRule();
  }

  public function loadObjects(
    PhabricatorObjectQuery $query,
    array $phids) {

    return id(new HeraldRuleQuery())
      ->setViewer($query->getViewer())
      ->withPHIDs($phids)
      ->execute();
  }

  public function loadHandles(
    PhabricatorHandleQuery $query,
    array $handles,
    array $objects) {

    foreach ($handles as $phid => $handle) {
      $rule = $objects[$phid];

      $id = $rule->getID();
      $name = $rule->getName();

      $handle->setName($name);
      $handle->setURI("/herald/rule/{$id}/");
    }
  }

}
