<?php

/*
 * Copyright 2012 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

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
    $this->attachHandleToFlag($flag);
    $ret = $this->buildFlagInfoDictionary($flag);
    $flag->delete();
    return $ret;
  }

}
