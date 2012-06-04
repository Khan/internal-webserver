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
final class ConduitAPI_conduit_getcertificate_Method extends ConduitAPIMethod {

  public function shouldRequireAuthentication() {
    return false;
  }

  public function shouldAllowUnguardedWrites() {
    // This method performs logging and is on the authentication pathway.
    return true;
  }

  public function getMethodDescription() {
    return "Retrieve certificate information for a user.";
  }

  public function defineParamTypes() {
    return array(
      'token' => 'required string',
      'host'  => 'required string',
    );
  }

  public function defineReturnType() {
    return 'dict<string, any>';
  }

  public function defineErrorTypes() {
    return array(
      "ERR-BAD-TOKEN" => "Token does not exist or has expired.",
      "ERR-RATE-LIMIT" =>
        "You have made too many invalid token requests recently. Wait before ".
        "making more.",
    );
  }

  protected function execute(ConduitAPIRequest $request) {
    $this->validateHost($request->getValue('host'));

    $failed_attempts = PhabricatorUserLog::loadRecentEventsFromThisIP(
      PhabricatorUserLog::ACTION_CONDUIT_CERTIFICATE_FAILURE,
      60 * 5);

    if (count($failed_attempts) > 5) {
      $this->logFailure();
      throw new ConduitException('ERR-RATE-LIMIT');
    }

    $token = $request->getValue('token');
    $info = id(new PhabricatorConduitCertificateToken())->loadOneWhere(
      'token = %s',
      trim($token));

    if (!$info || $info->getDateCreated() < time() - (60 * 15)) {
      $this->logFailure();
      throw new ConduitException('ERR-BAD-TOKEN');
    } else {
      $log = id(new PhabricatorUserLog())
        ->setActorPHID($info->getUserPHID())
        ->setUserPHID($info->getUserPHID())
        ->setAction(PhabricatorUserLog::ACTION_CONDUIT_CERTIFICATE)
        ->save();
    }

    $user = id(new PhabricatorUser())->loadOneWhere(
      'phid = %s',
      $info->getUserPHID());
    if (!$user) {
      throw new Exception("Certificate token points to an invalid user!");
    }

    return array(
      'username'    => $user->getUserName(),
      'certificate' => $user->getConduitCertificate(),
    );
  }

  private function logFailure() {

    $log = id(new PhabricatorUserLog())
      ->setUserPHID('-')
      ->setAction(PhabricatorUserLog::ACTION_CONDUIT_CERTIFICATE_FAILURE)
      ->save();
  }

}
