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
final class ConduitAPI_user_whoami_Method
  extends ConduitAPI_user_Method {

  public function getMethodDescription() {
    return "Retrieve information about the logged-in user.";
  }

  public function defineParamTypes() {
    return array(
    );
  }

  public function defineReturnType() {
    return 'nonempty dict<string, wild>';
  }

  public function defineErrorTypes() {
    return array(
    );
  }

  public function getRequiredScope() {
    return PhabricatorOAuthServerScope::SCOPE_WHOAMI;
  }

  protected function execute(ConduitAPIRequest $request) {
    return $this->buildUserInformationDictionary($request->getUser());
  }

}
