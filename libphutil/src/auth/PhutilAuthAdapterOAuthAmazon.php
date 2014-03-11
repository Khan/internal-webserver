<?php

/**
 * Authentication adapter for Amazon OAuth2.
 */
final class PhutilAuthAdapterOAuthAmazon extends PhutilAuthAdapterOAuth {

  public function getAdapterType() {
    return 'amazon';
  }

  public function getAdapterDomain() {
    return 'amazon.com';
  }

  public function getAccountID() {
    return $this->getOAuthAccountData('user_id');
  }

  public function getAccountEmail() {
    return $this->getOAuthAccountData('email');
  }

  public function getAccountName() {
    return null;
  }

  public function getAccountImageURI() {
    return null;
  }

  public function getAccountURI() {
    return null;
  }

  public function getAccountRealName() {
    return $this->getOAuthAccountData('name');
  }

  protected function getAuthenticateBaseURI() {
    return 'https://www.amazon.com/ap/oa';
  }

  protected function getTokenBaseURI() {
    return 'https://api.amazon.com/auth/o2/token';
  }

  public function getScope() {
    return 'profile';
  }

  public function getExtraAuthenticateParameters() {
    return array(
      'response_type' => 'code',
    );
  }

  public function getExtraTokenParameters() {
    return array(
      'grant_type' => 'authorization_code',
    );
  }

  protected function loadOAuthAccountData() {
    $uri = new PhutilURI('https://api.amazon.com/user/profile');
    $uri->setQueryParam('access_token', $this->getAccessToken());

    $future = new HTTPSFuture($uri);
    list($body) = $future->resolvex();

    $data = json_decode($body, true);
    if (!is_array($data)) {
      throw new Exception(
        "Expected valid JSON response from Amazon account data request, ".
        "got: ".$body);
    }

    return $data;
  }

}
