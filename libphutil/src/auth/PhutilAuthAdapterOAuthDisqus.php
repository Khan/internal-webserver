<?php

/**
 * Authentication adapter for Disqus OAuth2.
 */
final class PhutilAuthAdapterOAuthDisqus extends PhutilAuthAdapterOAuth {

  public function getAdapterType() {
    return 'disqus';
  }

  public function getAdapterDomain() {
    return 'disqus.com';
  }

  public function getAccountID() {
    return $this->getOAuthAccountData('id');
  }

  public function getAccountEmail() {
    return $this->getOAuthAccountData('email');
  }

  public function getAccountName() {
    return $this->getOAuthAccountData('username');
  }

  public function getAccountImageURI() {
    return $this->getOAuthAccountData('avatar', 'permalink');
  }

  public function getAccountURI() {
    return $this->getOAuthAccountData('profileUrl');
  }

  public function getAccountRealName() {
    return $this->getOAuthAccountData('name');
  }

  protected function getAuthenticateBaseURI() {
    return 'https://disqus.com/api/oauth/2.0/authorize/';
  }

  protected function getTokenBaseURI() {
    return 'https://disqus.com/api/oauth/2.0/access_token/';
  }

  public function getScope() {
    return 'read';
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
    $uri = new PhutilURI('https://disqus.com/api/3.0/users/details.json');
    $uri->setQueryParam('api_key', $this->getClientID());
    $uri->setQueryParam('access_token', $this->getAccessToken());
    $uri = (string)$uri;

    $future = new HTTPSFuture($uri);
    $future->setMethod('GET');
    list($body) = $future->resolvex();

    $data = json_decode($body, true);
    if (!is_array($data)) {
      throw new Exception(
        "Expected valid JSON response from Disqus account data request, ".
        "got: ".$body);
    }

    return $data['response'];
  }

}
