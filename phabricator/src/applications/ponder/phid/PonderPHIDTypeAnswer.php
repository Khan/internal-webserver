<?php

final class PonderPHIDTypeAnswer extends PhabricatorPHIDType {

  const TYPECONST = 'ANSW';

  public function getTypeConstant() {
    return self::TYPECONST;
  }

  public function getTypeName() {
    return pht('Answer');
  }

  public function newObject() {
    return new PonderAnswer();
  }

  protected function buildQueryForObjects(
    PhabricatorObjectQuery $query,
    array $phids) {

    return id(new PonderAnswerQuery())
      ->withPHIDs($phids);
  }

  public function loadHandles(
    PhabricatorHandleQuery $query,
    array $handles,
    array $objects) {

    foreach ($handles as $phid => $handle) {
      $answer = $objects[$phid];

      $id = $answer->getID();

      $handle->setName("Answer {$id}");
      $handle->setURI($answer->getURI());
    }
  }

}
