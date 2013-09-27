<?php

final class PhabricatorUser
  extends PhabricatorUserDAO
  implements
    PhutilPerson,
    PhabricatorPolicyInterface,
    PhabricatorCustomFieldInterface {

  const SESSION_TABLE = 'phabricator_session';
  const NAMETOKEN_TABLE = 'user_nametoken';
  const MAXIMUM_USERNAME_LENGTH = 64;

  protected $phid;
  protected $userName;
  protected $realName;
  protected $sex;
  protected $translation;
  protected $passwordSalt;
  protected $passwordHash;
  protected $profileImagePHID;
  protected $timezoneIdentifier = '';

  protected $consoleEnabled = 0;
  protected $consoleVisible = 0;
  protected $consoleTab = '';

  protected $conduitCertificate;

  protected $isSystemAgent = 0;
  protected $isAdmin = 0;
  protected $isDisabled = 0;

  private $profileImage = null;
  private $profile = null;
  private $status = self::ATTACHABLE;
  private $preferences = null;
  private $omnipotent = false;
  private $customFields = self::ATTACHABLE;

  protected function readField($field) {
    switch ($field) {
      case 'timezoneIdentifier':
        // If the user hasn't set one, guess the server's time.
        return nonempty(
          $this->timezoneIdentifier,
          date_default_timezone_get());
      // Make sure these return booleans.
      case 'isAdmin':
        return (bool)$this->isAdmin;
      case 'isDisabled':
        return (bool)$this->isDisabled;
      case 'isSystemAgent':
        return (bool)$this->isSystemAgent;
      default:
        return parent::readField($field);
    }
  }

  public function getConfiguration() {
    return array(
      self::CONFIG_AUX_PHID => true,
      self::CONFIG_PARTIAL_OBJECTS => true,
    ) + parent::getConfiguration();
  }

  public function generatePHID() {
    return PhabricatorPHID::generateNewPHID(
      PhabricatorPeoplePHIDTypeUser::TYPECONST);
  }

  public function setPassword(PhutilOpaqueEnvelope $envelope) {
    if (!$this->getPHID()) {
      throw new Exception(
        "You can not set a password for an unsaved user because their PHID ".
        "is a salt component in the password hash.");
    }

    if (!strlen($envelope->openEnvelope())) {
      $this->setPasswordHash('');
    } else {
      $this->setPasswordSalt(md5(mt_rand()));
      $hash = $this->hashPassword($envelope);
      $this->setPasswordHash($hash);
    }
    return $this;
  }

  // To satisfy PhutilPerson.
  public function getSex() {
    return $this->sex;
  }

  public function getTranslation() {
    try {
      if ($this->translation &&
          class_exists($this->translation) &&
          is_subclass_of($this->translation, 'PhabricatorTranslation')) {
        return $this->translation;
      }
    } catch (PhutilMissingSymbolException $ex) {
      return null;
    }
    return null;
  }

  public function isLoggedIn() {
    return !($this->getPHID() === null);
  }

  public function save() {
    if (!$this->getConduitCertificate()) {
      $this->setConduitCertificate($this->generateConduitCertificate());
    }
    $result = parent::save();

    if ($this->profile) {
      $this->profile->save();
    }

    $this->updateNameTokens();

    id(new PhabricatorSearchIndexer())
      ->indexDocumentByPHID($this->getPHID());

    return $result;
  }

  private function generateConduitCertificate() {
    return Filesystem::readRandomCharacters(255);
  }

  public function comparePassword(PhutilOpaqueEnvelope $envelope) {
    if (!strlen($envelope->openEnvelope())) {
      return false;
    }
    if (!strlen($this->getPasswordHash())) {
      return false;
    }
    $password_hash = $this->hashPassword($envelope);
    return ($password_hash === $this->getPasswordHash());
  }

  private function hashPassword(PhutilOpaqueEnvelope $envelope) {
    $hash = $this->getUsername().
            $envelope->openEnvelope().
            $this->getPHID().
            $this->getPasswordSalt();
    for ($ii = 0; $ii < 1000; $ii++) {
      $hash = md5($hash);
    }
    return $hash;
  }

  const CSRF_CYCLE_FREQUENCY  = 3600;
  const CSRF_SALT_LENGTH      = 8;
  const CSRF_TOKEN_LENGTH     = 16;
  const CSRF_BREACH_PREFIX    = 'B@';

  const EMAIL_CYCLE_FREQUENCY = 86400;
  const EMAIL_TOKEN_LENGTH    = 24;

  private function getRawCSRFToken($offset = 0) {
    return $this->generateToken(
      time() + (self::CSRF_CYCLE_FREQUENCY * $offset),
      self::CSRF_CYCLE_FREQUENCY,
      PhabricatorEnv::getEnvConfig('phabricator.csrf-key'),
      self::CSRF_TOKEN_LENGTH);
  }

  /**
   * @phutil-external-symbol class PhabricatorStartup
   */
  public function getCSRFToken() {
    $salt = PhabricatorStartup::getGlobal('csrf.salt');
    if (!$salt) {
      $salt = Filesystem::readRandomCharacters(self::CSRF_SALT_LENGTH);
      PhabricatorStartup::setGlobal('csrf.salt', $salt);
    }

    // Generate a token hash to mitigate BREACH attacks against SSL. See
    // discussion in T3684.
    $token = $this->getRawCSRFToken();
    $hash = PhabricatorHash::digest($token, $salt);
    return 'B@'.$salt.substr($hash, 0, self::CSRF_TOKEN_LENGTH);
  }

  public function validateCSRFToken($token) {
    if (!$this->getPHID()) {
      return true;
    }

    $salt = null;

    $version = 'plain';

    // This is a BREACH-mitigating token. See T3684.
    $breach_prefix = self::CSRF_BREACH_PREFIX;
    $breach_prelen = strlen($breach_prefix);

    if (!strncmp($token, $breach_prefix, $breach_prelen)) {
      $version = 'breach';
      $salt = substr($token, $breach_prelen, self::CSRF_SALT_LENGTH);
      $token = substr($token, $breach_prelen + self::CSRF_SALT_LENGTH);
    }

    // When the user posts a form, we check that it contains a valid CSRF token.
    // Tokens cycle each hour (every CSRF_CYLCE_FREQUENCY seconds) and we accept
    // either the current token, the next token (users can submit a "future"
    // token if you have two web frontends that have some clock skew) or any of
    // the last 6 tokens. This means that pages are valid for up to 7 hours.
    // There is also some Javascript which periodically refreshes the CSRF
    // tokens on each page, so theoretically pages should be valid indefinitely.
    // However, this code may fail to run (if the user loses their internet
    // connection, or there's a JS problem, or they don't have JS enabled).
    // Choosing the size of the window in which we accept old CSRF tokens is
    // an issue of balancing concerns between security and usability. We could
    // choose a very narrow (e.g., 1-hour) window to reduce vulnerability to
    // attacks using captured CSRF tokens, but it's also more likely that real
    // users will be affected by this, e.g. if they close their laptop for an
    // hour, open it back up, and try to submit a form before the CSRF refresh
    // can kick in. Since the user experience of submitting a form with expired
    // CSRF is often quite bad (you basically lose data, or it's a big pain to
    // recover at least) and I believe we gain little additional protection
    // by keeping the window very short (the overwhelming value here is in
    // preventing blind attacks, and most attacks which can capture CSRF tokens
    // can also just capture authentication information [sniffing networks]
    // or act as the user [xss]) the 7 hour default seems like a reasonable
    // balance. Other major platforms have much longer CSRF token lifetimes,
    // like Rails (session duration) and Django (forever), which suggests this
    // is a reasonable analysis.
    $csrf_window = 6;

    for ($ii = -$csrf_window; $ii <= 1; $ii++) {
      $valid = $this->getRawCSRFToken($ii);
      switch ($version) {
        // TODO: We can remove this after the BREACH version has been in the
        // wild for a while.
        case 'plain':
          if ($token == $valid) {
            return true;
          }
          break;
        case 'breach':
          $digest = PhabricatorHash::digest($valid, $salt);
          if (substr($digest, 0, self::CSRF_TOKEN_LENGTH) == $token) {
            return true;
          }
          break;
        default:
          throw new Exception("Unknown CSRF token format!");
      }
    }

    return false;
  }

  private function generateToken($epoch, $frequency, $key, $len) {
    $time_block = floor($epoch / $frequency);
    $vec = $this->getPHID().$this->getPasswordHash().$key.$time_block;
    return substr(PhabricatorHash::digest($vec), 0, $len);
  }

  /**
   * Issue a new session key to this user. Phabricator supports different
   * types of sessions (like "web" and "conduit") and each session type may
   * have multiple concurrent sessions (this allows a user to be logged in on
   * multiple browsers at the same time, for instance).
   *
   * Note that this method is transport-agnostic and does not set cookies or
   * issue other types of tokens, it ONLY generates a new session key.
   *
   * You can configure the maximum number of concurrent sessions for various
   * session types in the Phabricator configuration.
   *
   * @param   string  Session type, like "web".
   * @return  string  Newly generated session key.
   */
  public function establishSession($session_type) {
    $conn_w = $this->establishConnection('w');

    if (strpos($session_type, '-') !== false) {
      throw new Exception("Session type must not contain hyphen ('-')!");
    }

    // We allow multiple sessions of the same type, so when a caller requests
    // a new session of type "web", we give them the first available session in
    // "web-1", "web-2", ..., "web-N", up to some configurable limit. If none
    // of these sessions is available, we overwrite the oldest session and
    // reissue a new one in its place.

    $session_limit = 1;
    switch ($session_type) {
      case 'web':
        $session_limit = PhabricatorEnv::getEnvConfig('auth.sessions.web');
        break;
      case 'conduit':
        $session_limit = PhabricatorEnv::getEnvConfig('auth.sessions.conduit');
        break;
      default:
        throw new Exception("Unknown session type '{$session_type}'!");
    }

    $session_limit = (int)$session_limit;
    if ($session_limit <= 0) {
      throw new Exception(
        "Session limit for '{$session_type}' must be at least 1!");
    }

    // NOTE: Session establishment is sensitive to race conditions, as when
    // piping `arc` to `arc`:
    //
    //   arc export ... | arc paste ...
    //
    // To avoid this, we overwrite an old session only if it hasn't been
    // re-established since we read it.

    // Consume entropy to generate a new session key, forestalling the eventual
    // heat death of the universe.
    $session_key = Filesystem::readRandomCharacters(40);

    // Load all the currently active sessions.
    $sessions = queryfx_all(
      $conn_w,
      'SELECT type, sessionKey, sessionStart FROM %T
        WHERE userPHID = %s AND type LIKE %>',
      PhabricatorUser::SESSION_TABLE,
      $this->getPHID(),
      $session_type.'-');
    $sessions = ipull($sessions, null, 'type');
    $sessions = isort($sessions, 'sessionStart');

    $existing_sessions = array_keys($sessions);

    // UNGUARDED WRITES: Logging-in users don't have CSRF stuff yet.
    $unguarded = AphrontWriteGuard::beginScopedUnguardedWrites();

    $retries = 0;
    while (true) {


      // Choose which 'type' we'll actually establish, i.e. what number we're
      // going to append to the basic session type. To do this, just check all
      // the numbers sequentially until we find an available session.
      $establish_type = null;
      for ($ii = 1; $ii <= $session_limit; $ii++) {
        $try_type = $session_type.'-'.$ii;
        if (!in_array($try_type, $existing_sessions)) {
          $establish_type = $try_type;
          $expect_key = PhabricatorHash::digest($session_key);
          $existing_sessions[] = $try_type;

          // Ensure the row exists so we can issue an update below. We don't
          // care if we race here or not.
          queryfx(
            $conn_w,
            'INSERT IGNORE INTO %T (userPHID, type, sessionKey, sessionStart)
              VALUES (%s, %s, %s, 0)',
            self::SESSION_TABLE,
            $this->getPHID(),
            $establish_type,
            PhabricatorHash::digest($session_key));
          break;
        }
      }

      // If we didn't find an available session, choose the oldest session and
      // overwrite it.
      if (!$establish_type) {
        $oldest = reset($sessions);
        $establish_type = $oldest['type'];
        $expect_key = $oldest['sessionKey'];
      }

      // This is so that we'll only overwrite the session if it hasn't been
      // refreshed since we read it. If it has, the session key will be
      // different and we know we're racing other processes. Whichever one
      // won gets the session, we go back and try again.

      queryfx(
        $conn_w,
        'UPDATE %T SET sessionKey = %s, sessionStart = UNIX_TIMESTAMP()
          WHERE userPHID = %s AND type = %s AND sessionKey = %s',
        self::SESSION_TABLE,
        PhabricatorHash::digest($session_key),
        $this->getPHID(),
        $establish_type,
        $expect_key);

      if ($conn_w->getAffectedRows()) {
        // The update worked, so the session is valid.
        break;
      } else {
        // We know this just got grabbed, so don't try it again.
        unset($sessions[$establish_type]);
      }

      if (++$retries > $session_limit) {
        throw new Exception("Failed to establish a session!");
      }
    }

    $log = PhabricatorUserLog::newLog(
      $this,
      $this,
      PhabricatorUserLog::ACTION_LOGIN);
    $log->setDetails(
      array(
        'session_type' => $session_type,
        'session_issued' => $establish_type,
      ));
    $log->setSession($session_key);
    $log->save();

    return $session_key;
  }

  public function destroySession($session_key) {
    $conn_w = $this->establishConnection('w');
    queryfx(
      $conn_w,
      'DELETE FROM %T WHERE userPHID = %s AND sessionKey = %s',
      self::SESSION_TABLE,
      $this->getPHID(),
      PhabricatorHash::digest($session_key));
  }

  private function generateEmailToken(
    PhabricatorUserEmail $email,
    $offset = 0) {

    $key = implode(
      '-',
      array(
        PhabricatorEnv::getEnvConfig('phabricator.csrf-key'),
        $this->getPHID(),
        $email->getVerificationCode(),
      ));

    return $this->generateToken(
      time() + ($offset * self::EMAIL_CYCLE_FREQUENCY),
      self::EMAIL_CYCLE_FREQUENCY,
      $key,
      self::EMAIL_TOKEN_LENGTH);
  }

  public function validateEmailToken(
    PhabricatorUserEmail $email,
    $token) {
    for ($ii = -1; $ii <= 1; $ii++) {
      $valid = $this->generateEmailToken($email, $ii);
      if ($token == $valid) {
        return true;
      }
    }
    return false;
  }

  public function getEmailLoginURI(PhabricatorUserEmail $email = null) {
    if (!$email) {
      $email = $this->loadPrimaryEmail();
      if (!$email) {
        throw new Exception("User has no primary email!");
      }
    }
    $token = $this->generateEmailToken($email);
    $uri = PhabricatorEnv::getProductionURI('/login/etoken/'.$token.'/');
    $uri = new PhutilURI($uri);
    return $uri->alter('email', $email->getAddress());
  }

  public function attachUserProfile(PhabricatorUserProfile $profile) {
    $this->profile = $profile;
    return $this;
  }

  public function loadUserProfile() {
    if ($this->profile) {
      return $this->profile;
    }

    $profile_dao = new PhabricatorUserProfile();
    $this->profile = $profile_dao->loadOneWhere('userPHID = %s',
      $this->getPHID());

    if (!$this->profile) {
      $profile_dao->setUserPHID($this->getPHID());
      $this->profile = $profile_dao;
    }

    return $this->profile;
  }

  public function loadPrimaryEmailAddress() {
    $email = $this->loadPrimaryEmail();
    if (!$email) {
      throw new Exception("User has no primary email address!");
    }
    return $email->getAddress();
  }

  public function loadPrimaryEmail() {
    return $this->loadOneRelative(
      new PhabricatorUserEmail(),
      'userPHID',
      'getPHID',
      '(isPrimary = 1)');
  }

  public function loadPreferences() {
    if ($this->preferences) {
      return $this->preferences;
    }

    $preferences = null;
    if ($this->getPHID()) {
      $preferences = id(new PhabricatorUserPreferences())->loadOneWhere(
        'userPHID = %s',
        $this->getPHID());
    }

    if (!$preferences) {
      $preferences = new PhabricatorUserPreferences();
      $preferences->setUserPHID($this->getPHID());

      $default_dict = array(
        PhabricatorUserPreferences::PREFERENCE_TITLES => 'glyph',
        PhabricatorUserPreferences::PREFERENCE_EDITOR => '',
        PhabricatorUserPreferences::PREFERENCE_MONOSPACED => '',
        PhabricatorUserPreferences::PREFERENCE_DARK_CONSOLE => 0);

      $preferences->setPreferences($default_dict);
    }

    $this->preferences = $preferences;
    return $preferences;
  }

  public function loadEditorLink($path, $line, $callsign) {
    $editor = $this->loadPreferences()->getPreference(
      PhabricatorUserPreferences::PREFERENCE_EDITOR);

    if (is_array($path)) {
      $multiedit = $this->loadPreferences()->getPreference(
        PhabricatorUserPreferences::PREFERENCE_MULTIEDIT);
      switch ($multiedit) {
        case '':
          $path = implode(' ', $path);
          break;
        case 'disable':
          return null;
      }
    }

    if ($editor) {
      return strtr($editor, array(
        '%%' => '%',
        '%f' => phutil_escape_uri($path),
        '%l' => phutil_escape_uri($line),
        '%r' => phutil_escape_uri($callsign),
      ));
    }
  }

  private static function tokenizeName($name) {
    if (function_exists('mb_strtolower')) {
      $name = mb_strtolower($name, 'UTF-8');
    } else {
      $name = strtolower($name);
    }
    $name = trim($name);
    if (!strlen($name)) {
      return array();
    }
    return preg_split('/\s+/', $name);
  }

  /**
   * Populate the nametoken table, which used to fetch typeahead results. When
   * a user types "linc", we want to match "Abraham Lincoln" from on-demand
   * typeahead sources. To do this, we need a separate table of name fragments.
   */
  public function updateNameTokens() {
    $tokens = array_merge(
      self::tokenizeName($this->getRealName()),
      self::tokenizeName($this->getUserName()));
    $tokens = array_unique($tokens);
    $table  = self::NAMETOKEN_TABLE;
    $conn_w = $this->establishConnection('w');

    $sql = array();
    foreach ($tokens as $token) {
      $sql[] = qsprintf(
        $conn_w,
        '(%d, %s)',
        $this->getID(),
        $token);
    }

    queryfx(
      $conn_w,
      'DELETE FROM %T WHERE userID = %d',
      $table,
      $this->getID());
    if ($sql) {
      queryfx(
        $conn_w,
        'INSERT INTO %T (userID, token) VALUES %Q',
        $table,
        implode(', ', $sql));
    }
  }

  public function sendWelcomeEmail(PhabricatorUser $admin) {
    $admin_username = $admin->getUserName();
    $admin_realname = $admin->getRealName();
    $user_username = $this->getUserName();
    $is_serious = PhabricatorEnv::getEnvConfig('phabricator.serious-business');

    $base_uri = PhabricatorEnv::getProductionURI('/');

    $uri = $this->getEmailLoginURI();
    $body = <<<EOBODY
Welcome to Phabricator!

{$admin_username} ({$admin_realname}) has created an account for you.

  Username: {$user_username}

To login to Phabricator, follow this link and set a password:

  {$uri}

After you have set a password, you can login in the future by going here:

  {$base_uri}

EOBODY;

    if (!$is_serious) {
      $body .= <<<EOBODY

Love,
Phabricator

EOBODY;
    }

    $mail = id(new PhabricatorMetaMTAMail())
      ->addTos(array($this->getPHID()))
      ->setSubject('[Phabricator] Welcome to Phabricator')
      ->setBody($body)
      ->saveAndSend();
  }

  public function sendUsernameChangeEmail(
    PhabricatorUser $admin,
    $old_username) {

    $admin_username = $admin->getUserName();
    $admin_realname = $admin->getRealName();
    $new_username = $this->getUserName();

    $password_instructions = null;
    if (PhabricatorAuthProviderPassword::getPasswordProvider()) {
      $uri = $this->getEmailLoginURI();
      $password_instructions = <<<EOTXT
If you use a password to login, you'll need to reset it before you can login
again. You can reset your password by following this link:

  {$uri}

And, of course, you'll need to use your new username to login from now on. If
you use OAuth to login, nothing should change.

EOTXT;
    }

    $body = <<<EOBODY
{$admin_username} ({$admin_realname}) has changed your Phabricator username.

  Old Username: {$old_username}
  New Username: {$new_username}

{$password_instructions}
EOBODY;

    $mail = id(new PhabricatorMetaMTAMail())
      ->addTos(array($this->getPHID()))
      ->setSubject('[Phabricator] Username Changed')
      ->setBody($body)
      ->saveAndSend();
  }

  public static function describeValidUsername() {
    return pht(
      'Usernames must contain only numbers, letters, period, underscore and '.
      'hyphen, and can not end with a period. They must have no more than %d '.
      'characters.',
      new PhutilNumber(self::MAXIMUM_USERNAME_LENGTH));
  }

  public static function validateUsername($username) {
    // NOTE: If you update this, make sure to update:
    //
    //  - Remarkup rule for @mentions.
    //  - Routing rule for "/p/username/".
    //  - Unit tests, obviously.
    //  - describeValidUsername() method, above.

    if (strlen($username) > self::MAXIMUM_USERNAME_LENGTH) {
      return false;
    }

    return (bool)preg_match('/^[a-zA-Z0-9._-]*[a-zA-Z0-9_-]$/', $username);
  }

  public static function getDefaultProfileImageURI() {
    return celerity_get_resource_uri('/rsrc/image/avatar.png');
  }

  public function attachStatus(PhabricatorUserStatus $status) {
    $this->status = $status;
    return $this;
  }

  public function getStatus() {
    $this->assertAttached($this->status);
    return $this->status;
  }

  public function hasStatus() {
    return $this->status !== self::ATTACHABLE;
  }

  public function attachProfileImageURI($uri) {
    $this->profileImage = $uri;
    return $this;
  }

  public function loadProfileImageURI() {
    if ($this->profileImage) {
      return $this->profileImage;
    }

    $src_phid = $this->getProfileImagePHID();

    if ($src_phid) {
      $file = id(new PhabricatorFile())->loadOneWhere('phid = %s', $src_phid);
      if ($file) {
        $this->profileImage = $file->getBestURI();
      }
    }

    if (!$this->profileImage) {
      $this->profileImage = self::getDefaultProfileImageURI();
    }

    return $this->profileImage;
  }

  public function getFullName() {
    return $this->getUsername().' ('.$this->getRealName().')';
  }

  public function __toString() {
    return $this->getUsername();
  }

  public static function loadOneWithEmailAddress($address) {
    $email = id(new PhabricatorUserEmail())->loadOneWhere(
      'address = %s',
      $address);
    if (!$email) {
      return null;
    }
    return id(new PhabricatorUser())->loadOneWhere(
      'phid = %s',
      $email->getUserPHID());
  }


/* -(  Omnipotence  )-------------------------------------------------------- */


  /**
   * Returns true if this user is omnipotent. Omnipotent users bypass all policy
   * checks.
   *
   * @return bool True if the user bypasses policy checks.
   */
  public function isOmnipotent() {
    return $this->omnipotent;
  }


  /**
   * Get an omnipotent user object for use in contexts where there is no acting
   * user, notably daemons.
   *
   * @return PhabricatorUser An omnipotent user.
   */
  public static function getOmnipotentUser() {
    static $user = null;
    if (!$user) {
      $user = new PhabricatorUser();
      $user->omnipotent = true;
      $user->makeEphemeral();
    }
    return $user;
  }


/* -(  PhabricatorPolicyInterface  )----------------------------------------- */


  public function getCapabilities() {
    return array(
      PhabricatorPolicyCapability::CAN_VIEW,
      PhabricatorPolicyCapability::CAN_EDIT,
    );
  }

  public function getPolicy($capability) {
    switch ($capability) {
      case PhabricatorPolicyCapability::CAN_VIEW:
        return PhabricatorPolicies::POLICY_PUBLIC;
      case PhabricatorPolicyCapability::CAN_EDIT:
        return PhabricatorPolicies::POLICY_NOONE;
    }
  }

  public function hasAutomaticCapability($capability, PhabricatorUser $viewer) {
    return $this->getPHID() && ($viewer->getPHID() === $this->getPHID());
  }

  public function describeAutomaticCapability($capability) {
    switch ($capability) {
      case PhabricatorPolicyCapability::CAN_EDIT:
        return pht('Only you can edit your information.');
      default:
        return null;
    }
  }


/* -(  PhabricatorCustomFieldInterface  )------------------------------------ */


  public function getCustomFieldSpecificationForRole($role) {
    return PhabricatorEnv::getEnvConfig('user.fields');
  }

  public function getCustomFieldBaseClass() {
    return 'PhabricatorUserCustomField';
  }

  public function getCustomFields() {
    return $this->assertAttached($this->customFields);
  }

  public function attachCustomFields(PhabricatorCustomFieldAttachment $fields) {
    $this->customFields = $fields;
    return $this;
  }

}
