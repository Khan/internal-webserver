<?php

final class PhabricatorFilePHIDTypeFile extends PhabricatorPHIDType {

  const TYPECONST = 'FILE';

  public function getTypeConstant() {
    return self::TYPECONST;
  }

  public function getTypeName() {
    return pht('File');
  }

  public function newObject() {
    return new PhabricatorFile();
  }

  protected function buildQueryForObjects(
    PhabricatorObjectQuery $query,
    array $phids) {

    return id(new PhabricatorFileQuery())
      ->withPHIDs($phids);
  }

  public function loadHandles(
    PhabricatorHandleQuery $query,
    array $handles,
    array $objects) {

    foreach ($handles as $phid => $handle) {
      $file = $objects[$phid];

      $id = $file->getID();
      $name = $file->getName();
      $uri = $file->getBestURI();

      $handle->setName("F{$id}");
      $handle->setFullName("F{$id}: {$name}");
      $handle->setURI($uri);
    }
  }

  public function canLoadNamedObject($name) {
    return preg_match('/^F\d*[1-9]\d*$/', $name);
  }

  public function loadNamedObjects(
    PhabricatorObjectQuery $query,
    array $names) {

    $id_map = array();
    foreach ($names as $name) {
      $id = (int)substr($name, 1);
      $id_map[$id][] = $name;
    }

    $objects = id(new PhabricatorFileQuery())
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
