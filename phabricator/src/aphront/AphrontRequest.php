<?php

/**
 * @task data   Accessing Request Data
 * @task cookie Managing Cookies
 *
 */
final class AphrontRequest {

  // NOTE: These magic request-type parameters are automatically included in
  // certain requests (e.g., by phabricator_form(), JX.Request,
  // JX.Workflow, and ConduitClient) and help us figure out what sort of
  // response the client expects.

  const TYPE_AJAX = '__ajax__';
  const TYPE_FORM = '__form__';
  const TYPE_CONDUIT = '__conduit__';
  const TYPE_WORKFLOW = '__wflow__';
  const TYPE_CONTINUE = '__continue__';
  const TYPE_PREVIEW = '__preview__';

  private $host;
  private $path;
  private $requestData;
  private $user;
  private $applicationConfiguration;

  final public function __construct($host, $path) {
    $this->host = $host;
    $this->path = $path;
  }

  final public function setApplicationConfiguration(
    $application_configuration) {
    $this->applicationConfiguration = $application_configuration;
    return $this;
  }

  final public function getApplicationConfiguration() {
    return $this->applicationConfiguration;
  }

  final public function setPath($path) {
    $this->path = $path;
    return $this;
  }

  final public function getPath() {
    return $this->path;
  }

  final public function getHost() {
    // The "Host" header may include a port number, or may be a malicious
    // header in the form "realdomain.com:ignored@evil.com". Invoke the full
    // parser to extract the real domain correctly. See here for coverage of
    // a similar issue in Django:
    //
    //  https://www.djangoproject.com/weblog/2012/oct/17/security/
    $uri = new PhutilURI('http://'.$this->host);
    return $uri->getDomain();
  }


/* -(  Accessing Request Data  )--------------------------------------------- */


  /**
   * @task data
   */
  final public function setRequestData(array $request_data) {
    $this->requestData = $request_data;
    return $this;
  }


  /**
   * @task data
   */
  final public function getRequestData() {
    return $this->requestData;
  }


  /**
   * @task data
   */
  final public function getInt($name, $default = null) {
    if (isset($this->requestData[$name])) {
      return (int)$this->requestData[$name];
    } else {
      return $default;
    }
  }


  /**
   * @task data
   */
  final public function getBool($name, $default = null) {
    if (isset($this->requestData[$name])) {
      if ($this->requestData[$name] === 'true') {
        return true;
      } else if ($this->requestData[$name] === 'false') {
        return false;
      } else {
        return (bool)$this->requestData[$name];
      }
    } else {
      return $default;
    }
  }


  /**
   * @task data
   */
  final public function getStr($name, $default = null) {
    if (isset($this->requestData[$name])) {
      $str = (string)$this->requestData[$name];
      // Normalize newline craziness.
      $str = str_replace(
        array("\r\n", "\r"),
        array("\n", "\n"),
        $str);
      return $str;
    } else {
      return $default;
    }
  }


  /**
   * @task data
   */
  final public function getArr($name, $default = array()) {
    if (isset($this->requestData[$name]) &&
        is_array($this->requestData[$name])) {
      return $this->requestData[$name];
    } else {
      return $default;
    }
  }


  /**
   * @task data
   */
  final public function getStrList($name, $default = array()) {
    if (!isset($this->requestData[$name])) {
      return $default;
    }
    $list = $this->getStr($name);
    $list = preg_split('/[\s,]+/', $list, $limit = -1, PREG_SPLIT_NO_EMPTY);
    return $list;
  }


  /**
   * @task data
   */
  final public function getExists($name) {
    return array_key_exists($name, $this->requestData);
  }

  final public function getFileExists($name) {
    return isset($_FILES[$name]) &&
           (idx($_FILES[$name], 'error') !== UPLOAD_ERR_NO_FILE);
  }

  final public function isHTTPGet() {
    return ($_SERVER['REQUEST_METHOD'] == 'GET');
  }

  final public function isHTTPPost() {
    return ($_SERVER['REQUEST_METHOD'] == 'POST');
  }

  final public function isAjax() {
    return $this->getExists(self::TYPE_AJAX);
  }

  final public function isJavelinWorkflow() {
    return $this->getExists(self::TYPE_WORKFLOW);
  }

  final public function isConduit() {
    return $this->getExists(self::TYPE_CONDUIT);
  }

  public static function getCSRFTokenName() {
    return '__csrf__';
  }

  public static function getCSRFHeaderName() {
    return 'X-Phabricator-Csrf';
  }

  final public function validateCSRF() {
    $token_name = self::getCSRFTokenName();
    $token = $this->getStr($token_name);

    // No token in the request, check the HTTP header which is added for Ajax
    // requests.
    if (empty($token)) {
      $token = self::getHTTPHeader(self::getCSRFHeaderName());
    }

    $valid = $this->getUser()->validateCSRFToken($token);
    if (!$valid) {

      // Add some diagnostic details so we can figure out if some CSRF issues
      // are JS problems or people accessing Ajax URIs directly with their
      // browsers.
      $more_info = array();

      if ($this->isAjax()) {
        $more_info[] = pht('This was an Ajax request.');
      } else {
        $more_info[] = pht('This was a Web request.');
      }

      if ($token) {
        $more_info[] = pht('This request had an invalid CSRF token.');
      } else {
        $more_info[] = pht('This request had no CSRF token.');
      }

      // Give a more detailed explanation of how to avoid the exception
      // in developer mode.
      if (PhabricatorEnv::getEnvConfig('phabricator.developer-mode')) {
        // TODO: Clean this up, see T1921.
        $more_info[] =
          "To avoid this error, use phabricator_form() to construct forms. " .
          "If you are already using phabricator_form(), make sure the form " .
          "'action' uses a relative URI (i.e., begins with a '/'). Forms " .
          "using absolute URIs do not include CSRF tokens, to prevent " .
          "leaking tokens to external sites.\n\n" .
          "If this page performs writes which do not require CSRF " .
          "protection (usually, filling caches or logging), you can use " .
          "AphrontWriteGuard::beginScopedUnguardedWrites() to temporarily " .
          "bypass CSRF protection while writing. You should use this only " .
          "for writes which can not be protected with normal CSRF " .
          "mechanisms.\n\n" .
          "Some UI elements (like PhabricatorActionListView) also have " .
          "methods which will allow you to render links as forms (like " .
          "setRenderAsForm(true)).";
      }

      // This should only be able to happen if you load a form, pull your
      // internet for 6 hours, and then reconnect and immediately submit,
      // but give the user some indication of what happened since the workflow
      // is incredibly confusing otherwise.
      throw new AphrontCSRFException(
        pht(
          "You are trying to save some data to Phabricator, but the request ".
          "your browser made included an incorrect token. Reload the page ".
          "and try again. You may need to clear your cookies.\n\n%s",
          implode("\n", $more_info)));
    }

    return true;
  }

  final public function isFormPost() {
    $post = $this->getExists(self::TYPE_FORM) &&
            $this->isHTTPPost();

    if (!$post) {
      return false;
    }

    return $this->validateCSRF();
  }

  final public function setCookiePrefix($prefix) {
    $this->cookiePrefix = $prefix;
    return $this;
  }

  final private function getPrefixedCookieName($name) {
    if (strlen($this->cookiePrefix)) {
      return $this->cookiePrefix.'_'.$name;
    } else {
      return $name;
    }
  }

  final public function getCookie($name, $default = null) {
    $name = $this->getPrefixedCookieName($name);
    $value = idx($_COOKIE, $name, $default);

    // Internally, PHP deletes cookies by setting them to the value 'deleted'
    // with an expiration date in the past.

    // At least in Safari, the browser may send this cookie anyway in some
    // circumstances. After logging out, the 302'd GET to /login/ consistently
    // includes deleted cookies on my local install. If a cookie value is
    // literally 'deleted', pretend it does not exist.

    if ($value === 'deleted') {
      return null;
    }

    return $value;
  }

  final public function clearCookie($name) {
    $name = $this->getPrefixedCookieName($name);
    $this->setCookieWithExpiration($name, '', time() - (60 * 60 * 24 * 30));
    unset($_COOKIE[$name]);
  }

  /**
   * Get the domain which cookies should be set on for this request, or null
   * if the request does not correspond to a valid cookie domain.
   *
   * @return PhutilURI|null   Domain URI, or null if no valid domain exists.
   *
   * @task cookie
   */
  private function getCookieDomainURI() {
    if (PhabricatorEnv::getEnvConfig('security.require-https') &&
        !$this->isHTTPS()) {
      return null;
    }

    $host = $this->getHost();

    // If there's no base domain configured, just use whatever the request
    // domain is. This makes setup easier, and we'll tell administrators to
    // configure a base domain during the setup process.
    $base_uri = PhabricatorEnv::getEnvConfig('phabricator.base-uri');
    if (!strlen($base_uri)) {
      return new PhutilURI('http://'.$host.'/');
    }

    $alternates = PhabricatorEnv::getEnvConfig('phabricator.allowed-uris');
    $allowed_uris = array_merge(
      array($base_uri),
      $alternates);

    foreach ($allowed_uris as $allowed_uri) {
      $uri = new PhutilURI($allowed_uri);
      if ($uri->getDomain() == $host) {
        return $uri;
      }
    }

    return null;
  }

  /**
   * Determine if security policy rules will allow cookies to be set when
   * responding to the request.
   *
   * @return bool True if setCookie() will succeed. If this method returns
   *              false, setCookie() will throw.
   *
   * @task cookie
   */
  final public function canSetCookies() {
    return (bool)$this->getCookieDomainURI();
  }


  /**
   * Set a cookie which does not expire for a long time.
   *
   * To set a temporary cookie, see @{method:setTemporaryCookie}.
   *
   * @param string  Cookie name.
   * @param string  Cookie value.
   * @return this
   * @task cookie
   */
  final public function setCookie($name, $value) {
    $far_future = time() + (60 * 60 * 24 * 365 * 5);
    return $this->setCookieWithExpiration($name, $value, $far_future);
  }


  /**
   * Set a cookie which expires soon.
   *
   * To set a durable cookie, see @{method:setCookie}.
   *
   * @param string  Cookie name.
   * @param string  Cookie value.
   * @return this
   * @task cookie
   */
  final public function setTemporaryCookie($name, $value) {
    return $this->setCookieWithExpiration($name, $value, 0);
  }


  /**
   * Set a cookie with a given expiration policy.
   *
   * @param string  Cookie name.
   * @param string  Cookie value.
   * @param int     Epoch timestamp for cookie expiration.
   * @return this
   * @task cookie
   */
  final private function setCookieWithExpiration(
    $name,
    $value,
    $expire) {

    $is_secure = false;

    $base_domain_uri = $this->getCookieDomainURI();
    if (!$base_domain_uri) {
      $configured_as = PhabricatorEnv::getEnvConfig('phabricator.base-uri');
      $accessed_as = $this->getHost();

      throw new Exception(
        pht(
          'This Phabricator install is configured as "%s", but you are '.
          'using the domain name "%s" to access a page which is trying to '.
          'set a cookie. Acccess Phabricator on the configured primary '.
          'domain or a configured alternate domain. Phabricator will not '.
          'set cookies on other domains for security reasons.',
          $configured_as,
          $accessed_as));
    }

    $base_domain = $base_domain_uri->getDomain();
    $is_secure = ($base_domain_uri->getProtocol() == 'https');

    $name = $this->getPrefixedCookieName($name);

    if (php_sapi_name() == 'cli') {
      // Do nothing, to avoid triggering "Cannot modify header information"
      // warnings.

      // TODO: This is effectively a test for whether we're running in a unit
      // test or not. Move this actual call to HTTPSink?
    } else {
      setcookie(
        $name,
        $value,
        $expire,
        $path = '/',
        $base_domain,
        $is_secure,
        $http_only = true);
    }

    $_COOKIE[$name] = $value;

    return $this;
  }

  final public function setUser($user) {
    $this->user = $user;
    return $this;
  }

  final public function getUser() {
    return $this->user;
  }

  final public function getRequestURI() {
    $get = $_GET;
    unset($get['__path__']);
    $path = phutil_escape_uri($this->getPath());
    return id(new PhutilURI($path))->setQueryParams($get);
  }

  final public function isDialogFormPost() {
    return $this->isFormPost() && $this->getStr('__dialog__');
  }

  final public function getRemoteAddr() {
    return $_SERVER['REMOTE_ADDR'];
  }

  public function isHTTPS() {
    if (empty($_SERVER['HTTPS'])) {
      return false;
    }
    if (!strcasecmp($_SERVER["HTTPS"], "off")) {
      return false;
    }
    return true;
  }

  public function isContinueRequest() {
    return $this->isFormPost() && $this->getStr('__continue__');
  }

  public function isPreviewRequest() {
    return $this->isFormPost() && $this->getStr('__preview__');
  }

  /**
   * Get application request parameters in a flattened form suitable for
   * inclusion in an HTTP request, excluding parameters with special meanings.
   * This is primarily useful if you want to ask the user for more input and
   * then resubmit their request.
   *
   * @return  dict<string, string>  Original request parameters.
   */
  public function getPassthroughRequestParameters() {
    return self::flattenData($this->getPassthroughRequestData());
  }

  /**
   * Get request data other than "magic" parameters.
   *
   * @return dict<string, wild> Request data, with magic filtered out.
   */
  public function getPassthroughRequestData() {
    $data = $this->getRequestData();

    // Remove magic parameters like __dialog__ and __ajax__.
    foreach ($data as $key => $value) {
      if (!strncmp($key, '__', 2)) {
        unset($data[$key]);
      }
    }

    return $data;
  }


  /**
   * Flatten an array of key-value pairs (possibly including arrays as values)
   * into a list of key-value pairs suitable for submitting via HTTP request
   * (with arrays flattened).
   *
   * @param   dict<string, wild>    Data to flatten.
   * @return  dict<string, string>  Flat data suitable for inclusion in an HTTP
   *                                request.
   */
  public static function flattenData(array $data) {
    $result = array();
    foreach ($data as $key => $value) {
      if (is_array($value)) {
        foreach (self::flattenData($value) as $fkey => $fvalue) {
          $fkey = '['.preg_replace('/(?=\[)|$/', ']', $fkey, $limit = 1);
          $result[$key.$fkey] = $fvalue;
        }
      } else {
        $result[$key] = (string)$value;
      }
    }

    ksort($result);

    return $result;
  }


  /**
   * Read the value of an HTTP header from `$_SERVER`, or a similar datasource.
   *
   * This function accepts a canonical header name, like `"Accept-Encoding"`,
   * and looks up the appropriate value in `$_SERVER` (in this case,
   * `"HTTP_ACCEPT_ENCODING"`).
   *
   * @param   string        Canonical header name, like `"Accept-Encoding"`.
   * @param   wild          Default value to return if header is not present.
   * @param   array?        Read this instead of `$_SERVER`.
   * @return  string|wild   Header value if present, or `$default` if not.
   */
  public static function getHTTPHeader($name, $default = null, $data = null) {
    // PHP mangles HTTP headers by uppercasing them and replacing hyphens with
    // underscores, then prepending 'HTTP_'.
    $php_index = strtoupper($name);
    $php_index = str_replace('-', '_', $php_index);

    $try_names = array();

    $try_names[] = 'HTTP_'.$php_index;
    if ($php_index == 'CONTENT_TYPE' || $php_index == 'CONTENT_LENGTH') {
      // These headers may be available under alternate names. See
      // http://www.php.net/manual/en/reserved.variables.server.php#110763
      $try_names[] = $php_index;
    }

    if ($data === null) {
      $data = $_SERVER;
    }

    foreach ($try_names as $try_name) {
      if (array_key_exists($try_name, $data)) {
        return $data[$try_name];
      }
    }

    return $default;
  }

}
