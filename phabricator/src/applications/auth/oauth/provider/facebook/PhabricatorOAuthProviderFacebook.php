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

final class PhabricatorOAuthProviderFacebook extends PhabricatorOAuthProvider {

  private $userData;

  public function getProviderKey() {
    return self::PROVIDER_FACEBOOK;
  }

  public function getProviderName() {
    return 'Facebook';
  }

  public function isProviderEnabled() {
    return PhabricatorEnv::getEnvConfig('facebook.auth-enabled');
  }

  public function isProviderLinkPermanent() {
    return PhabricatorEnv::getEnvConfig('facebook.auth-permanent');
  }

  public function isProviderRegistrationEnabled() {
    return PhabricatorEnv::getEnvConfig('facebook.registration-enabled');
  }

  public function getClientID() {
    return PhabricatorEnv::getEnvConfig('facebook.application-id');
  }

  public function renderGetClientIDHelp() {
    return 'To generate an ID, sign into Facebook, install the "Developer"'.
           ' application, and use it to create a new Facebook application.';
  }

  public function getClientSecret() {
    return PhabricatorEnv::getEnvConfig('facebook.application-secret');
  }

  public function renderGetClientSecretHelp() {
    return 'You can find the application secret in the Facebook'.
           ' "Developer" application on Facebook.';
  }

  public function getAuthURI() {
    return 'https://www.facebook.com/dialog/oauth';
  }

  public function getTestURIs() {
    return array(
      'http://facebook.com',
      'https://graph.facebook.com/me'
    );
  }

  public function getTokenURI() {
    return 'https://graph.facebook.com/oauth/access_token';
  }

  protected function getTokenExpiryKey() {
    return 'expires';
  }

  public function getUserInfoURI() {
    return 'https://graph.facebook.com/me';
  }

  public function getMinimumScope() {
    return 'email';
  }

  public function setUserData($data) {
    $data = json_decode($data, true);
    $this->validateUserData($data);
    $this->userData = $data;
    return $this;
  }

  public function retrieveUserID() {
    return $this->userData['id'];
  }

  public function retrieveUserEmail() {
    return $this->userData['email'];
  }

  public function retrieveUserAccountName() {
    $matches = null;
    $link = $this->userData['link'];
    if (preg_match('@/([a-zA-Z0-9]+)$@', $link, $matches)) {
      return $matches[1];
    }
    return null;
  }

  public function retrieveUserProfileImage() {
    $uri = 'https://graph.facebook.com/me/picture?access_token=';
    return @file_get_contents($uri.$this->getAccessToken());
  }

  public function retrieveUserAccountURI() {
    return $this->userData['link'];
  }

  public function retrieveUserRealName() {
    return $this->userData['name'];
  }

  public function shouldDiagnoseAppLogin() {
    return true;
  }

}
