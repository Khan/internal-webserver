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

  protected function buildQueryForObjects(
    PhabricatorObjectQuery $query,
    array $phids) {

    return id(new HeraldRuleQuery())
      ->withPHIDs($phids);
  }

  public function loadHandles(
    PhabricatorHandleQuery $query,
    array $handles,
    array $objects) {

    foreach ($handles as $phid => $handle) {
      $rule = $objects[$phid];

      $id = $rule->getID();
      $name = $rule->getName();

      $handle->setName("H{$id}");
      $handle->setFullName("H{$id} {$name}");
      $handle->setURI("/herald/rule/{$id}/");
    }
  }

  public function canLoadNamedObject($name) {
    return preg_match('/^H\d*[1-9]\d*$/i', $name);
  }

  public function loadNamedObjects(
    PhabricatorObjectQuery $query,
    array $names) {

    $id_map = array();
    foreach ($names as $name) {
      $id = (int)substr($name, 1);
      $id_map[$id][] = $name;
    }

    $objects = id(new HeraldRuleQuery())
      ->setViewer($query->getViewer())
      ->withIDs(array_keys($id_map))
      ->execute();

    $results = array();
    foreach ($objects as $id => $object) {
      foreach (idx($id_map, $id, array()) as $name) {
        $results[$name] = $object;
      }
    }

    return $results;
  }

}
