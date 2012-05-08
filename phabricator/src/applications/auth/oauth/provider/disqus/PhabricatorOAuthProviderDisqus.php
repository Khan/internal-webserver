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

final class PhabricatorOAuthProviderDisqus extends PhabricatorOAuthProvider {

  private $userData;

  public function getProviderKey() {
    return self::PROVIDER_DISQUS;
  }

  public function getProviderName() {
    return 'Disqus';
  }

  public function isProviderEnabled() {
    return PhabricatorEnv::getEnvConfig('disqus.auth-enabled');
  }

  public function isProviderLinkPermanent() {
    return PhabricatorEnv::getEnvConfig('disqus.auth-permanent');
  }

  public function isProviderRegistrationEnabled() {
    return PhabricatorEnv::getEnvConfig('disqus.registration-enabled');
  }

  public function getClientID() {
    return PhabricatorEnv::getEnvConfig('disqus.application-id');
  }

  public function renderGetClientIDHelp() {
    return null;
  }

  public function getClientSecret() {
    return PhabricatorEnv::getEnvConfig('disqus.application-secret');
  }

  public function renderGetClientSecretHelp() {
    return null;
  }

  public function getAuthURI() {
    return 'https://disqus.com/api/oauth/2.0/authorize/';
  }

  public function getTokenURI() {
    return 'https://disqus.com/api/oauth/2.0/access_token/';
  }

  protected function getTokenExpiryKey() {
    return 'expires_in';
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

  public function getTestURIs() {
    return array(
      'http://disqus.com',
      $this->getUserInfoURI(),
    );
  }

  public function getUserInfoURI() {
    return 'https://disqus.com/api/3.0/users/details.json?'.
           'api_key='.$this->getClientID();
  }

  public function getMinimumScope() {
    return 'read';
  }

  public function setUserData($data) {
    $data = idx(json_decode($data, true), 'response');
    $this->validateUserData($data);
    $this->userData = $data;
    return $this;
  }

  public function retrieveUserID() {
    return $this->userData['id'];
  }

  public function retrieveUserEmail() {
    return idx($this->userData, 'email');
  }

  public function retrieveUserAccountName() {
    return $this->userData['username'];
  }

  public function retrieveUserProfileImage() {
    $avatar = idx($this->userData, 'avatar');
    if ($avatar) {
      $uri = idx($avatar, 'permalink');
      if ($uri) {
        return @file_get_contents($uri);
      }
    }
    return null;
  }

  public function retrieveUserAccountURI() {
    return idx($this->userData, 'profileUrl');
  }

  public function retrieveUserRealName() {
    return idx($this->userData, 'name');
  }

  public function shouldDiagnoseAppLogin() {
    return true;
  }
}
