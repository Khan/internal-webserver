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

final class PhabricatorOAuthProviderGoogle extends PhabricatorOAuthProvider {

  private $userData;

  public function getProviderKey() {
    return self::PROVIDER_GOOGLE;
  }

  public function getProviderName() {
    return 'Google';
  }

  public function isProviderEnabled() {
    return PhabricatorEnv::getEnvConfig('google.auth-enabled');
  }

  public function isProviderLinkPermanent() {
    return PhabricatorEnv::getEnvConfig('google.auth-permanent');
  }

  public function isProviderRegistrationEnabled() {
    return PhabricatorEnv::getEnvConfig('google.registration-enabled');
  }

  public function getClientID() {
    return PhabricatorEnv::getEnvConfig('google.application-id');
  }

  public function renderGetClientIDHelp() {
    return null;
  }

  public function getClientSecret() {
    return PhabricatorEnv::getEnvConfig('google.application-secret');
  }

  public function renderGetClientSecretHelp() {
    return null;
  }

  public function getAuthURI() {
    return 'https://accounts.google.com/o/oauth2/auth';
  }

  public function getTestURIs() {
    return array(
      'http://www.google.com'
    );
  }

  public function getTokenURI() {
    return 'https://accounts.google.com/o/oauth2/token';
  }

  protected function getTokenExpiryKey() {
    return 'expires_in';
  }

  public function getUserInfoURI() {
    return 'https://www.googleapis.com/oauth2/v1/userinfo';
  }

  public function getMinimumScope() {
    $scopes = array(
      'https://www.googleapis.com/auth/userinfo.email',
      'https://www.googleapis.com/auth/userinfo.profile',
    );

    return implode(' ', $scopes);
  }

  public function setUserData($data) {
    $data = json_decode($data, true);
    $this->validateUserData($data);

    // Guess account name from email address, this is just a hint anyway.
    $data['account'] = head(explode('@', $data['email']));

    $this->userData = $data;
    return $this;
  }

  public function retrieveUserID() {
    return $this->userData['email'];
  }

  public function retrieveUserEmail() {
    return $this->userData['email'];
  }

  public function retrieveUserAccountName() {
    return $this->userData['account'];
  }

  public function retrieveUserProfileImage() {
    // No apparent API access to Plus yet.
    return null;
  }

  public function retrieveUserAccountURI() {
    // No apparent API access to Plus yet.
    return null;
  }

  public function retrieveUserRealName() {
    return $this->userData['name'];
  }

  public function getExtraAuthParameters() {
    return array(
      'response_type' => 'code',
    );
  }

  public function getExtraTokenParameters() {
    return array(
      'grant_type' => 'authorization_code',
    );

  }

  public function decodeTokenResponse($response) {
    return json_decode($response, true);
  }

}
