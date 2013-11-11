<?php

/**
 *
 * @task data Accessing Request Data
 *
 * @group aphront
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
      if ($token) {
        $token_info = "with an invalid CSRF token";
      } else {
        $token_info = "without a CSRF token";
      }

      if ($this->isAjax()) {
        $more_info = "(This was an Ajax request, {$token_info}.)";
      } else {
        $more_info = "(This was a web request, {$token_info}.)";
      }

      // Give a more detailed explanation of how to avoid the exception
      // in developer mode.
      if (PhabricatorEnv::getEnvConfig('phabricator.developer-mode')) {
        $more_info = $more_info .
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
        "The form you just submitted did not include a valid CSRF token. ".
        "This token is a technical security measure which prevents a ".
        "certain type of login hijacking attack. However, the token can ".
        "become invalid if you leave a page open for more than six hours ".
        "without a connection to the internet. To fix this problem: reload ".
        "the page, and then resubmit it. All data inserted to the form will ".
        "be lost in some browsers so copy them somewhere before reloading.\n\n".
        $more_info);
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

  final public function getCookie($name, $default = null) {
    return idx($_COOKIE, $name, $default);
  }

  final public function clearCookie($name) {
    $this->setCookie($name, '', time() - (60 * 60 * 24 * 30));
    unset($_COOKIE[$name]);
  }

  final public function setCookie($name, $value, $expire = null) {

    $is_secure = false;

    // If a base URI has been configured, ensure cookies are only set on that
    // domain. Also, use the URI protocol to control SSL-only cookies.
    $base_uri = PhabricatorEnv::getEnvConfig('phabricator.base-uri');
    if ($base_uri) {
      $alternates = PhabricatorEnv::getEnvConfig('phabricator.allowed-uris');
      $allowed_uris = array_merge(
        array($base_uri),
        $alternates);

      $host = $this->getHost();

      $match = null;
      foreach ($allowed_uris as $allowed_uri) {
        $uri = new PhutilURI($allowed_uri);
        $domain = $uri->getDomain();
        if ($host == $domain) {
          $match = $uri;
          break;
        }
      }

      if ($match === null) {
        if (count($allowed_uris) > 1) {
          throw new Exception(
            pht(
              'This Phabricator install is configured as "%s", but you are '.
              'accessing it via "%s". Access Phabricator via the primary '.
              'configured domain, or one of the permitted alternate '.
              'domains: %s. Phabricator will not set cookies on other domains '.
              'for security reasons.',
              $base_uri,
              $host,
              implode(', ', $alternates)));
        } else {
          throw new Exception(
            pht(
              'This Phabricator install is configured as "%s", but you are '.
              'accessing it via "%s". Acccess Phabricator via the primary '.
              'configured domain. Phabricator will not set cookies on other '.
              'domains for security reasons.',
              $base_uri,
              $host));
        }
      }

      $base_domain = $match->getDomain();
      $is_secure = ($match->getProtocol() == 'https');
    } else {
      $base_uri = new PhutilURI(PhabricatorEnv::getRequestBaseURI());
      $base_domain = $base_uri->getDomain();
    }

    if ($expire === null) {
      $expire = time() + (60 * 60 * 24 * 365 * 5);
    }


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
