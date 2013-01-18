<?php

/**
 * Manages the execution environment configuration, exposing APIs to read
 * configuration settings and other similar values that are derived directly
 * from configuration settings.
 *
 *
 * = Reading Configuration =
 *
 * The primary role of this class is to provide an API for reading
 * Phabricator configuration, @{method:getEnvConfig}:
 *
 *   $value = PhabricatorEnv::getEnvConfig('some.key', $default);
 *
 * The class also handles some URI construction based on configuration, via
 * the methods @{method:getURI}, @{method:getProductionURI},
 * @{method:getCDNURI}, and @{method:getDoclink}.
 *
 * For configuration which allows you to choose a class to be responsible for
 * some functionality (e.g., which mail adapter to use to deliver email),
 * @{method:newObjectFromConfig} provides a simple interface that validates
 * the configured value.
 *
 *
 * = Unit Test Support =
 *
 * In unit tests, you can use @{method:beginScopedEnv} to create a temporary,
 * mutable environment. The method returns a scope guard object which restores
 * the environment when it is destroyed. For example:
 *
 *   public function testExample() {
 *     $env = PhabricatorEnv::beginScopedEnv();
 *     $env->overrideEnv('some.key', 'new-value-for-this-test');
 *
 *     // Some test which depends on the value of 'some.key'.
 *
 *   }
 *
 * Your changes will persist until the `$env` object leaves scope or is
 * destroyed.
 *
 * You should //not// use this in normal code.
 *
 *
 * @task read     Reading Configuration
 * @task uri      URI Validation
 * @task test     Unit Test Support
 * @task internal Internals
 */
final class PhabricatorEnv {

  private static $sourceStack;
  private static $repairSource;

  /**
   * @phutil-external-symbol class PhabricatorStartup
   */
  public static function initializeWebEnvironment() {
    $env = self::getSelectedEnvironmentName();
    if (!$env) {
      PhabricatorStartup::didFatal(
        "The 'PHABRICATOR_ENV' environmental variable is not defined. Modify ".
        "your httpd.conf to include 'SetEnv PHABRICATOR_ENV <env>', where ".
        "'<env>' is one of 'development', 'production', or a custom ".
        "environment.");
    }

    self::initializeCommonEnvironment();
  }

  public static function initializeScriptEnvironment() {
    $env = self::getSelectedEnvironmentName();
    if (!$env) {
      echo phutil_console_wrap(
        phutil_console_format(
          "**ERROR**: PHABRICATOR_ENV Not Set\n\n".
          "Define the __PHABRICATOR_ENV__ environment variable before ".
          "running this script. You can do it on the command line like ".
          "this:\n\n".
          "  $ PHABRICATOR_ENV=__custom/myconfig__ %s ...\n\n".
          "Replace __custom/myconfig__ with the path to your configuration ".
          "file. For more information, see the 'Configuration Guide' in the ".
          "Phabricator documentation.\n\n",
          $GLOBALS['argv'][0]));
      exit(1);
    }

    self::initializeCommonEnvironment();

    // NOTE: This is dangerous in general, but we know we're in a script context
    // and are not vulnerable to CSRF.
    AphrontWriteGuard::allowDangerousUnguardedWrites(true);

    // There are several places where we log information (about errors, events,
    // service calls, etc.) for analysis via DarkConsole or similar. These are
    // useful for web requests, but grow unboundedly in long-running scripts and
    // daemons. Discard data as it arrives in these cases.
    PhutilServiceProfiler::getInstance()->enableDiscardMode();
    DarkConsoleErrorLogPluginAPI::enableDiscardMode();
    DarkConsoleEventPluginAPI::enableDiscardMode();
  }


  private static function initializeCommonEnvironment() {
    self::buildConfigurationSourceStack();

    PhutilErrorHandler::initialize();

    $tz = PhabricatorEnv::getEnvConfig('phabricator.timezone');
    if ($tz) {
      date_default_timezone_set($tz);
    }

    // Append any paths to $PATH if we need to.
    $paths = PhabricatorEnv::getEnvConfig('environment.append-paths');
    if (!empty($paths)) {
      $current_env_path = getenv('PATH');
      $new_env_paths = implode(PATH_SEPARATOR, $paths);
      putenv('PATH='.$current_env_path.PATH_SEPARATOR.$new_env_paths);
    }

    PhabricatorEventEngine::initialize();

    $translation = PhabricatorEnv::newObjectFromConfig('translation.provider');
    PhutilTranslator::getInstance()
      ->setLanguage($translation->getLanguage())
      ->addTranslations($translation->getTranslations());
  }

  private static function buildConfigurationSourceStack() {
    $stack = new PhabricatorConfigStackSource();
    self::$sourceStack = $stack;

    $stack->pushSource(
      id(new PhabricatorConfigDefaultSource())
        ->setName(pht('Global Default')));

    $env = self::getSelectedEnvironmentName();
    $stack->pushSource(
      id(new PhabricatorConfigFileSource($env))
        ->setName(pht("File '%s'", $env)));

    $stack->pushSource(
      id(new PhabricatorConfigLocalSource())
        ->setName(pht("Local Config")));

    // If the install overrides the database adapter, we might need to load
    // the database adapter class before we can push on the database config.
    // This config is locked and can't be edited from the web UI anyway.
    foreach (PhabricatorEnv::getEnvConfig('load-libraries') as $library) {
      phutil_load_library($library);
    }

    try {
      $stack->pushSource(
        id(new PhabricatorConfigDatabaseSource('default'))
          ->setName(pht("Database")));
    } catch (AphrontQueryException $recoverable) {
      // If the database is not available, just skip this configuration
      // source. This happens during `bin/storage upgrade`, `bin/conf` before
      // schema setup, etc.
    }
  }

  public static function repairConfig($key, $value) {
    if (!self::$repairSource) {
      self::$repairSource = id(new PhabricatorConfigDictionarySource(array()))
        ->setName(pht("Repaired Config"));
      self::$sourceStack->pushSource(self::$repairSource);
    }
    self::$repairSource->setKeys(array($key => $value));
  }

  public static function getUnrepairedEnvConfig($key, $default = null) {
    foreach (self::$sourceStack->getStack() as $source) {
      if ($source === self::$repairSource) {
        continue;
      }
      $result = $source->getKeys(array($key));
      if ($result) {
        return $result[$key];
      }
    }
    return $default;
  }

  public static function getSelectedEnvironmentName() {
    $env_var = 'PHABRICATOR_ENV';

    $env = idx($_SERVER, $env_var);

    if (!$env) {
      $env = getenv($env_var);
    }

    if (!$env) {
      $env = idx($_ENV, $env_var);
    }

    if (!$env) {
      $root = dirname(phutil_get_library_root('phabricator'));
      $path = $root.'/conf/local/ENVIRONMENT';
      if (Filesystem::pathExists($path)) {
        $env = trim(Filesystem::readFile($path));
      }
    }

    return $env;
  }


/* -(  Reading Configuration  )---------------------------------------------- */


  /**
   * Get the current configuration setting for a given key.
   *
   * @task read
   */
  public static function getEnvConfig($key, $default = null) {
    $result = self::$sourceStack->getKeys(array($key));
    return idx($result, $key, $default);
  }


  /**
   * Get the fully-qualified URI for a path.
   *
   * @task read
   */
  public static function getURI($path) {
    return rtrim(self::getEnvConfig('phabricator.base-uri'), '/').$path;
  }


  /**
   * Get the fully-qualified production URI for a path.
   *
   * @task read
   */
  public static function getProductionURI($path) {
    // If we're passed a URI which already has a domain, simply return it
    // unmodified. In particular, files may have URIs which point to a CDN
    // domain.
    $uri = new PhutilURI($path);
    if ($uri->getDomain()) {
      return $path;
    }

    $production_domain = self::getEnvConfig('phabricator.production-uri');
    if (!$production_domain) {
      $production_domain = self::getEnvConfig('phabricator.base-uri');
    }
    return rtrim($production_domain, '/').$path;
  }


  /**
   * Get the fully-qualified production URI for a static resource path.
   *
   * @task read
   */
  public static function getCDNURI($path) {
    $alt = self::getEnvConfig('security.alternate-file-domain');
    if (!$alt) {
      $alt = self::getEnvConfig('phabricator.base-uri');
    }
    $uri = new PhutilURI($alt);
    $uri->setPath($path);
    return (string)$uri;
  }


  /**
   * Get the fully-qualified production URI for a documentation resource.
   *
   * @task read
   */
  public static function getDoclink($resource) {
    return 'http://www.phabricator.com/docs/phabricator/'.$resource;
  }


  /**
   * Build a concrete object from a configuration key.
   *
   * @task read
   */
  public static function newObjectFromConfig($key, $args = array()) {
    $class = self::getEnvConfig($key);
    return newv($class, $args);
  }


/* -(  Unit Test Support  )-------------------------------------------------- */


  /**
   * @task test
   */
  public static function beginScopedEnv() {
    return new PhabricatorScopedEnv(self::pushTestEnvironment());
  }


  /**
   * @task test
   */
  private static function pushTestEnvironment() {
    $source = new PhabricatorConfigDictionarySource(array());
    self::$sourceStack->pushSource($source);
    return spl_object_hash($source);
  }


  /**
   * @task test
   */
  public static function popTestEnvironment($key) {
    $source = self::$sourceStack->popSource();
    $stack_key = spl_object_hash($source);
    if ($stack_key !== $key) {
      self::$sourceStack->pushSource($source);
      throw new Exception(
        "Scoped environments were destroyed in a diffent order than they ".
        "were initialized.");
    }
  }


/* -(  URI Validation  )----------------------------------------------------- */


  /**
   * Detect if a URI satisfies either @{method:isValidLocalWebResource} or
   * @{method:isValidRemoteWebResource}, i.e. is a page on this server or the
   * URI of some other resource which has a valid protocol. This rejects
   * garbage URIs and URIs with protocols which do not appear in the
   * ##uri.allowed-protocols## configuration, notably 'javascript:' URIs.
   *
   * NOTE: This method is generally intended to reject URIs which it may be
   * unsafe to put in an "href" link attribute.
   *
   * @param string URI to test.
   * @return bool True if the URI identifies a web resource.
   * @task uri
   */
  public static function isValidWebResource($uri) {
    return self::isValidLocalWebResource($uri) ||
           self::isValidRemoteWebResource($uri);
  }


  /**
   * Detect if a URI identifies some page on this server.
   *
   * NOTE: This method is generally intended to reject URIs which it may be
   * unsafe to issue a "Location:" redirect to.
   *
   * @param string URI to test.
   * @return bool True if the URI identifies a local page.
   * @task uri
   */
  public static function isValidLocalWebResource($uri) {
    $uri = (string)$uri;

    if (!strlen($uri)) {
      return false;
    }

    if (preg_match('/\s/', $uri)) {
      // PHP hasn't been vulnerable to header injection attacks for a bunch of
      // years, but we can safely reject these anyway since they're never valid.
      return false;
    }

    // Valid URIs must begin with '/', followed by the end of the string or some
    // other non-'/' character. This rejects protocol-relative URIs like
    // "//evil.com/evil_stuff/".
    return (bool)preg_match('@^/([^/]|$)@', $uri);
  }


  /**
   * Detect if a URI identifies some valid remote resource.
   *
   * @param string URI to test.
   * @return bool True if a URI idenfies a remote resource with an allowed
   *              protocol.
   * @task uri
   */
  public static function isValidRemoteWebResource($uri) {
    $uri = (string)$uri;

    $proto = id(new PhutilURI($uri))->getProtocol();
    if (!$proto) {
      return false;
    }

    $allowed = self::getEnvConfig('uri.allowed-protocols');
    if (empty($allowed[$proto])) {
      return false;
    }

    return true;
  }


/* -(  Internals  )---------------------------------------------------------- */


  /**
   * @task internal
   */
  public static function envConfigExists($key) {
    return array_key_exists($key, self::$sourceStack->getKeys(array($key)));
  }


  /**
   * @task internal
   */
  public static function getAllConfigKeys() {
    return self::$sourceStack->getAllKeys();
  }

  public static function getConfigSourceStack() {
    return self::$sourceStack;
  }

  /**
   * @task internal
   */
  public static function overrideTestEnvConfig($stack_key, $key, $value) {
    $tmp = array();

    // If we don't have the right key, we'll throw when popping the last
    // source off the stack.
    do {
      $source = self::$sourceStack->popSource();
      array_unshift($tmp, $source);
      if (spl_object_hash($source) == $stack_key) {
        $source->setKeys(array($key => $value));
        break;
      }
    } while (true);

    foreach ($tmp as $source) {
      self::$sourceStack->pushSource($source);
    }
  }

}
