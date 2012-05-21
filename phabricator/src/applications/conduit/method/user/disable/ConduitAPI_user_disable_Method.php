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
final class ConduitAPI_user_disable_Method
  extends ConduitAPI_user_Method {

  public function getMethodDescription() {
    return "Permanently disable specified users (admin only).";
  }

  public function defineParamTypes() {
    return array(
      'phids' => 'required list<phid>',
    );
  }

  public function defineReturnType() {
    return 'void';
  }

  public function defineErrorTypes() {
    return array(
      'ERR-PERMISSIONS' => 'Only admins can call this method.',
      'ERR-BAD-PHID' => 'Non existent user PHID.',
    );
  }

  protected function execute(ConduitAPIRequest $request) {
    if (!$request->getUser()->getIsAdmin()) {
      throw new ConduitException('ERR-PERMISSIONS');
    }

    $phids = $request->getValue('phids');

    $users = id(new PhabricatorUser())->loadAllWhere(
      'phid IN (%Ls)',
      $phids);

    if (count($phids) != count($users)) {
      throw new ConduitException('ERR-BAD-PHID');
    }

    foreach ($users as $user) {
      $user->setIsDisabled(true);
      $user->save();
    }
  }

}
