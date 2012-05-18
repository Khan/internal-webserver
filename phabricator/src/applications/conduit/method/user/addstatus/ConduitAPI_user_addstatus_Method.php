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
final class ConduitAPI_user_addstatus_Method extends ConduitAPI_user_Method {

  public function getMethodStatus() {
    return self::METHOD_STATUS_UNSTABLE;
  }

  public function getMethodDescription() {
    return "Add status information to the logged-in user.";
  }

  public function defineParamTypes() {
    return array(
      'fromEpoch' => 'required int',
      'toEpoch' => 'required int',
      'status' => 'required enum<away, sporadic>',
    );
  }

  public function defineReturnType() {
    return 'void';
  }

  public function defineErrorTypes() {
    return array(
      'ERR-BAD-EPOCH' => "'toEpoch' must be bigger than 'fromEpoch'.",
      'ERR-OVERLAP' =>
        'There must be no status in any part of the specified epoch.',
    );
  }

  protected function execute(ConduitAPIRequest $request) {
    $user_phid = $request->getUser()->getPHID();
    $from = $request->getValue('fromEpoch');
    $to = $request->getValue('toEpoch');

    if ($to <= $from) {
      throw new ConduitException('ERR-BAD-EPOCH');
    }

    $table = new PhabricatorUserStatus();
    $table->openTransaction();
    $table->beginWriteLocking();

    $overlap = $table->loadAllWhere(
      'userPHID = %s AND dateFrom < %d AND dateTo > %d',
      $user_phid,
      $to,
      $from);
    if ($overlap) {
      $table->endWriteLocking();
      $table->killTransaction();
      throw new ConduitException('ERR-OVERLAP');
    }

    id(new PhabricatorUserStatus())
      ->setUserPHID($user_phid)
      ->setDateFrom($from)
      ->setDateTo($to)
      ->setTextStatus($request->getValue('status'))
      ->save();

    $table->endWriteLocking();
    $table->saveTransaction();
  }

}
