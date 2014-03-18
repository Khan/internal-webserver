<?php

final class PhabricatorOAuthServerPHIDTypeClient
  extends PhabricatorPHIDType {

  const TYPECONST = 'OASC';

  public function getTypeConstant() {
    return self::TYPECONST;
  }

  public function getTypeName() {
    return pht('OAuth Application');
  }

  public function newObject() {
    return new PhabricatorOAuthServerClient();
  }

  protected function buildQueryForObjects(
    PhabricatorObjectQuery $query,
    array $phids) {

    return id(new PhabricatorOAuthServerClientQuery())
      ->withPHIDs($phids);
  }

  public function loadHandles(
    PhabricatorHandleQuery $query,
    array $handles,
    array $objects) {

    foreach ($handles as $phid => $handle) {
      $client = $objects[$phid];

      $handle->setName($client->getName());
    }
  }

}
