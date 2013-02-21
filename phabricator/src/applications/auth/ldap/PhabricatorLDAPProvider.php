<?php

final class PhabricatorLDAPProvider {
  // http://www.php.net/manual/en/function.ldap-errno.php#20665 states
  // that the number could be 31 or 49, in testing it has always been 49
  const LDAP_INVALID_CREDENTIALS = 49;

  private $userData;
  private $connection;

  public function __construct() {

  }

  public function __destruct() {
    if (isset($this->connection)) {
      ldap_unbind($this->connection);
    }
  }

  public function isProviderEnabled() {
    return PhabricatorEnv::getEnvConfig('ldap.auth-enabled');
  }

  public function getHostname() {
    return PhabricatorEnv::getEnvConfig('ldap.hostname');
  }

  public function getPort() {
    return PhabricatorEnv::getEnvConfig('ldap.port');
  }

  public function getBaseDN() {
    return PhabricatorEnv::getEnvConfig('ldap.base_dn');
  }

  public function getSearchAttribute() {
    return PhabricatorEnv::getEnvConfig('ldap.search_attribute');
  }

  public function getUsernameAttribute() {
    return PhabricatorEnv::getEnvConfig('ldap.username-attribute');
  }

  public function getLDAPVersion() {
    return PhabricatorEnv::getEnvConfig('ldap.version');
  }

  public function getLDAPReferrals() {
    return PhabricatorEnv::getEnvConfig('ldap.referrals');
  }

  public function getLDAPStartTLS() {
    return PhabricatorEnv::getEnvConfig('ldap.start-tls');
  }

  public function bindAnonymousUserEnabled() {
    return strlen(trim($this->getAnonymousUserName())) > 0;
  }

  public function getAnonymousUserName() {
    return PhabricatorEnv::getEnvConfig('ldap.anonymous-user-name');
  }

  public function getAnonymousUserPassword() {
    return PhabricatorEnv::getEnvConfig('ldap.anonymous-user-password');
  }

  public function retrieveUserEmail() {
    return $this->userData['mail'][0];
  }

  public function retrieveUserRealName() {
    return $this->retrieveUserRealNameFromData($this->userData);
  }

  public function retrieveUserRealNameFromData($data) {
    $name_attributes = PhabricatorEnv::getEnvConfig(
        'ldap.real_name_attributes');

    $real_name = '';
    if (is_array($name_attributes)) {
      foreach ($name_attributes AS $attribute) {
        if (isset($data[$attribute][0])) {
          $real_name .= $data[$attribute][0].' ';
        }
      }

      trim($real_name);
    } else if (isset($data[$name_attributes][0])) {
      $real_name = $data[$name_attributes][0];
    }

    if ($real_name == '') {
      return null;
    }

    return $real_name;
  }

  public function retrieveUsername() {
    $key = nonempty(
      $this->getUsernameAttribute(),
      $this->getSearchAttribute());
    return $this->userData[$key][0];
  }

  public function getConnection() {
    if (!isset($this->connection)) {
      $this->connection = ldap_connect($this->getHostname(), $this->getPort());

      if (!$this->connection) {
        throw new Exception('Could not connect to LDAP host at '.
          $this->getHostname().':'.$this->getPort());
      }

      ldap_set_option($this->connection, LDAP_OPT_PROTOCOL_VERSION,
        $this->getLDAPVersion());
      ldap_set_option($this->connection, LDAP_OPT_REFERRALS,
       $this->getLDAPReferrals());

      if ($this->getLDAPStartTLS()) {
        if (!ldap_start_tls($this->getConnection())) {
          throw new Exception('Unabled to initialize STARTTLS for LDAP host at '.
            $this->getHostname().':'.$this->getPort());
        }
      }
    }

    return $this->connection;
  }

  public function getUserData() {
    return $this->userData;
  }

  private function invalidLDAPUserErrorMessage($errno, $errmsg) {
    return "LDAP Error #".$errno.": ".$errmsg;
  }

  public function auth($username, PhutilOpaqueEnvelope $password) {
    if (strlen(trim($username)) == 0) {
      throw new Exception('Username can not be empty');
    }

    if (PhabricatorEnv::getEnvConfig('ldap.search-first')) {
      // To protect against people phishing for accounts we catch the
      // exception and present the default exception that would be presented
      // in the case of a failed bind.
      try {
        $user = $this->getUser($this->getUsernameAttribute(), $username);
        $username = $user[$this->getSearchAttribute()][0];
      } catch (PhabricatorLDAPUnknownUserException $e) {
        throw new Exception(
          $this->invalidLDAPUserErrorMessage(
            self::LDAP_INVALID_CREDENTIALS,
            ldap_err2str(self::LDAP_INVALID_CREDENTIALS)));
      }
    }

    $conn = $this->getConnection();

    $activeDirectoryDomain =
      PhabricatorEnv::getEnvConfig('ldap.activedirectory_domain');

    if ($activeDirectoryDomain) {
      $dn = $username.'@'.$activeDirectoryDomain;
    } else {
      if (isset($user)) {
        $dn = $user['dn'];
      } else {
        $dn = ldap_sprintf(
          '%Q=%s,%Q',
          $this->getSearchAttribute(),
          $username,
          $this->getBaseDN());
      }
    }

    // NOTE: It is very important we suppress any messages that occur here,
    // because it logs passwords if it reaches an error log of any sort.
    DarkConsoleErrorLogPluginAPI::enableDiscardMode();
    $result = @ldap_bind($conn, $dn, $password->openEnvelope());
    DarkConsoleErrorLogPluginAPI::disableDiscardMode();

    if (!$result) {
      throw new Exception(
        $this->invalidLDAPUserErrorMessage(
          ldap_errno($conn),
          ldap_error($conn)));
    }

    $this->userData = $this->getUser($this->getSearchAttribute(), $username);
    return $this->userData;
  }

  private function getUser($attribute, $username) {
    $conn = $this->getConnection();

    if ($this->bindAnonymousUserEnabled()) {
      // NOTE: It is very important we suppress any messages that occur here,
      // because it logs passwords if it reaches an error log of any sort.
      DarkConsoleErrorLogPluginAPI::enableDiscardMode();
      $result = ldap_bind(
        $conn,
        $this->getAnonymousUserName(),
        $this->getAnonymousUserPassword());
      DarkConsoleErrorLogPluginAPI::disableDiscardMode();

      if (!$result) {
        throw new Exception('Bind anonymous account failed. '.
          $this->invalidLDAPUserErrorMessage(
            ldap_errno($conn),
            ldap_error($conn)));
      }
    }

    $query = ldap_sprintf(
      '%Q=%S',
      $attribute,
      $username);

    $result = ldap_search($conn, $this->getBaseDN(), $query);

    if (!$result) {
      throw new Exception('Search failed. '.
        $this->invalidLDAPUserErrorMessage(
          ldap_errno($conn),
          ldap_error($conn)));
    }

    $entries = ldap_get_entries($conn, $result);

    if ($entries === false) {
      throw new Exception('Could not get entries');
    }

    if ($entries['count'] > 1) {
      throw new Exception('Found more then one user with this '.
        $attribute);
    }

    if ($entries['count'] == 0) {
      throw new PhabricatorLDAPUnknownUserException('Could not find user');
    }

    return $entries[0];
  }

  public function search($query) {
    $result = ldap_search($this->getConnection(), $this->getBaseDN(),
              $query);

    if (!$result) {
      throw new Exception('Search failed. Please check your LDAP and HTTP '.
        'logs for more information.');
    }

    $entries = ldap_get_entries($this->getConnection(), $result);

    if ($entries === false) {
      throw new Exception('Could not get entries');
    }

    if ($entries['count'] == 0) {
      throw new Exception('No results found');
    }


    $rows = array();

    for ($i = 0; $i < $entries['count']; $i++) {
      $row = array();
      $entry = $entries[$i];

      // Get username, email and realname
      $username = $entry[$this->getSearchAttribute()][0];
      if (empty($username)) {
        continue;
      }
      $row[] = $username;
      $row[] = $entry['mail'][0];
      $row[] = $this->retrieveUserRealNameFromData($entry);


      $rows[] = $row;
    }

    return $rows;

  }
}
