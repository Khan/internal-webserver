<?php

/**
 * @group conduit
 */
final class ConduitAPI_flag_delete_Method extends ConduitAPI_flag_Method {

  public function getMethodDescription() {
    return "Clear a flag.";
  }

  public function defineParamTypes() {
    return array(
      'id'         => 'optional id',
      'objectPHID' => 'optional phid',
    );
  }

  public function defineReturnType() {
    return 'dict | null';
  }

  public function defineErrorTypes() {
    return array(
      'ERR_NOT_FOUND'  => 'Bad flag ID.',
      'ERR_WRONG_USER' => 'You are not the creator of this flag.',
      'ERR_NEED_PARAM' => 'Must pass an id or an objectPHID.',
    );
  }

  protected function execute(ConduitAPIRequest $request) {
    $id = $request->getValue('id');
    $object = $request->getValue('objectPHID');
    if ($id) {
      $flag = id(new PhabricatorFlag())->load($id);
      if (!$flag) {
        throw new ConduitException('ERR_NOT_FOUND');
      }
      if ($flag->getOwnerPHID() != $request->getUser()->getPHID()) {
        throw new ConduitException('ERR_WRONG_USER');
      }
    } elseif ($object) {
      $flag = id(new PhabricatorFlag())->loadOneWhere(
        'objectPHID = %s AND ownerPHID = %s',
        $object,
        $request->getUser()->getPHID());
      if (!$flag) {
        return null;
      }
    } else {
      throw new ConduitException('ERR_NEED_PARAM');
    }
    $this->attachHandleToFlag($flag, $request->getUser());
    $ret = $this->buildFlagInfoDictionary($flag);
    $flag->delete();
    return $ret;
  }

}
