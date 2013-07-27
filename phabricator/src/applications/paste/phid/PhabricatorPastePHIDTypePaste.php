<?php

final class PhabricatorPastePHIDTypePaste extends PhabricatorPHIDType {

  const TYPECONST = 'PSTE';

  public function getTypeConstant() {
    return self::TYPECONST;
  }

  public function getTypeName() {
    return pht('Paste');
  }

  public function newObject() {
    return new PhabricatorPaste();
  }

  public function loadObjects(
    PhabricatorObjectQuery $query,
    array $phids) {

    return id(new PhabricatorPasteQuery())
      ->setViewer($query->getViewer())
      ->withPHIDs($phids)
      ->execute();
  }

  public function loadHandles(
    PhabricatorHandleQuery $query,
    array $handles,
    array $objects) {

    foreach ($handles as $phid => $handle) {
      $paste = $objects[$phid];

      $id = $paste->getID();
      $name = $paste->getFullName();

      $handle->setName("P{$id}");
      $handle->setFullName("P{$id}: {$name}");
      $handle->setURI("/P{$id}");
    }
  }

  public function canLoadNamedObject($name) {
    return preg_match('/^P\d*[1-9]\d*$/i', $name);
  }

  public function loadNamedObjects(
    PhabricatorObjectQuery $query,
    array $names) {

    $id_map = array();
    foreach ($names as $name) {
      $id = (int)substr($name, 1);
      $id_map[$id][] = $name;
    }

    $objects = id(new PhabricatorPasteQuery())
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
