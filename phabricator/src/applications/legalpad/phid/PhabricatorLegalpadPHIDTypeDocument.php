<?php

/**
 * @group legalpad
 */
final class PhabricatorLegalpadPHIDTypeDocument extends PhabricatorPHIDType {

  const TYPECONST = 'LEGD';

  public function getTypeConstant() {
    return self::TYPECONST;
  }

  public function getTypeName() {
    return pht('Legalpad Document');
  }

  public function newObject() {
    return new LegalpadDocument();
  }

  public function loadObjects(
    PhabricatorObjectQuery $query,
    array $phids) {

    return id(new LegalpadDocumentQuery())
      ->setViewer($query->getViewer())
      ->setParentQuery($query)
      ->withPHIDs($phids)
      ->needDocumentBodies(true)
      ->execute();
  }

  public function loadHandles(
    PhabricatorHandleQuery $query,
    array $handles,
    array $objects) {

    foreach ($handles as $phid => $handle) {
      $document = $objects[$phid];
      $name = $document->getDocumentBody()->getTitle();
      $handle->setName($name);
      $handle->setFullName($name);
      $handle->setURI('/legalpad/view/'.$document->getID().'/');
    }
  }

  public function canLoadNamedObject($name) {
    return preg_match('/^L\d*[1-9]\d*$/i', $name);
  }

  public function loadNamedObjects(
    PhabricatorObjectQuery $query,
    array $names) {

    $id_map = array();
    foreach ($names as $name) {
      $id = (int)substr($name, 1);
      $id_map[$id][] = $name;
    }

    $objects = id(new LegalpadDocumentQuery())
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
