<?php

/**
 * Implements a runnable command, like "arc diff" or "arc help".
 *
 * = Managing Conduit =
 *
 * Workflows have the builtin ability to open a Conduit connection to a
 * Phabricator installation, so methods can be invoked over the API. Workflows
 * may either not need this (e.g., "help"), or may need a Conduit but not
 * authentication (e.g., calling only public APIs), or may need a Conduit and
 * authentication (e.g., "arc diff").
 *
 * To specify that you need an //unauthenticated// conduit, override
 * @{method:requiresConduit} to return ##true##. To specify that you need an
 * //authenticated// conduit, override @{method:requiresAuthentication} to
 * return ##true##. You can also manually invoke @{method:establishConduit}
 * and/or @{method:authenticateConduit} later in a workflow to upgrade it.
 * Once a conduit is open, you can access the client by calling
 * @{method:getConduit}, which allows you to invoke methods. You can get
 * verified information about the user identity by calling @{method:getUserPHID}
 * or @{method:getUserName} after authentication occurs.
 *
 * = Scratch Files =
 *
 * Arcanist workflows can read and write 'scratch files', which are temporary
 * files stored in the project that persist across commands. They can be useful
 * if you want to save some state, or keep a copy of a long message the user
 * entered if something goes wrong.
 *
 *
 * @task  conduit   Conduit
 * @task  scratch   Scratch Files
 * @group workflow
 * @stable
 */
abstract class ArcanistBaseWorkflow extends Phobject {

  const COMMIT_DISABLE = 0;
  const COMMIT_ALLOW = 1;
  const COMMIT_ENABLE = 2;

  const AUTO_COMMIT_TITLE = 'Automatic commit by arc';

  private $commitMode = self::COMMIT_DISABLE;
  private $shouldAmend;

  private $conduit;
  private $conduitURI;
  private $conduitCredentials;
  private $conduitAuthenticated;
  private $forcedConduitVersion;
  private $conduitTimeout;

  private $userPHID;
  private $userName;
  private $repositoryAPI;
  private $workingCopy;
  private $arguments;
  private $passedArguments;
  private $command;

  private $projectInfo;

  private $arcanistConfiguration;
  private $parentWorkflow;
  private $workingDirectory;
  private $repositoryVersion;

  private $changeCache = array();


  public function __construct() {

  }


  abstract public function run();

  /**
   * Return the command used to invoke this workflow from the command like,
   * e.g. "help" for @{class:ArcanistHelpWorkflow}.
   *
   * @return string   The command a user types to invoke this workflow.
   */
  abstract public function getWorkflowName();

  /**
   * Return console formatted string with all command synopses.
   *
   * @return string  6-space indented list of available command synopses.
   */
  abstract public function getCommandSynopses();

  /**
   * Return console formatted string with command help printed in `arc help`.
   *
   * @return string  10-space indented help to use the command.
   */
  abstract public function getCommandHelp();


/* -(  Conduit  )------------------------------------------------------------ */


  /**
   * Set the URI which the workflow will open a conduit connection to when
   * @{method:establishConduit} is called. Arcanist makes an effort to set
   * this by default for all workflows (by reading ##.arcconfig## and/or the
   * value of ##--conduit-uri##) even if they don't need Conduit, so a workflow
   * can generally upgrade into a conduit workflow later by just calling
   * @{method:establishConduit}.
   *
   * You generally should not need to call this method unless you are
   * specifically overriding the default URI. It is normally sufficient to
   * just invoke @{method:establishConduit}.
   *
   * NOTE: You can not call this after a conduit has been established.
   *
   * @param string  The URI to open a conduit to when @{method:establishConduit}
   *                is called.
   * @return this
   * @task conduit
   */
  final public function setConduitURI($conduit_uri) {
    if ($this->conduit) {
      throw new Exception(
        "You can not change the Conduit URI after a conduit is already open.");
    }
    $this->conduitURI = $conduit_uri;
    return $this;
  }

  /**
   * Returns the URI the conduit connection within the workflow uses.
   *
   * @return string
   * @task conduit
   */
  final public function getConduitURI() {
    return $this->conduitURI;
  }

  /**
   * Open a conduit channel to the server which was previously configured by
   * calling @{method:setConduitURI}. Arcanist will do this automatically if
   * the workflow returns ##true## from @{method:requiresConduit}, or you can
   * later upgrade a workflow and build a conduit by invoking it manually.
   *
   * You must establish a conduit before you can make conduit calls.
   *
   * NOTE: You must call @{method:setConduitURI} before you can call this
   * method.
   *
   * @return this
   * @task conduit
   */
  final public function establishConduit() {
    if ($this->conduit) {
      return $this;
    }

    if (!$this->conduitURI) {
      throw new Exception(
        "You must specify a Conduit URI with setConduitURI() before you can ".
        "establish a conduit.");
    }

    $this->conduit = new ConduitClient($this->conduitURI);

    if ($this->conduitTimeout) {
      $this->conduit->setTimeout($this->conduitTimeout);
    }

    $user = $this->getConfigFromWhateverSourceAvailiable('http.basicauth.user');
    $pass = $this->getConfigFromWhateverSourceAvailiable('http.basicauth.pass');
    if ($user !== null && $pass !== null) {
      $this->conduit->setBasicAuthCredentials($user, $pass);
    }

    return $this;
  }

  final public function getConfigFromWhateverSourceAvailiable($key) {
    if ($this->requiresWorkingCopy()) {
      $working_copy = $this->getWorkingCopy();
      return $working_copy->getConfigFromAnySource($key);
    } else {
      $global_config = self::readGlobalArcConfig();
      $pval = idx($global_config, $key);

      if ($pval === null) {
        $system_config = self::readSystemArcConfig();
        $pval = idx($system_config, $key);
      }

      return $pval;
    }
  }


  /**
   * Set credentials which will be used to authenticate against Conduit. These
   * credentials can then be used to establish an authenticated connection to
   * conduit by calling @{method:authenticateConduit}. Arcanist sets some
   * defaults for all workflows regardless of whether or not they return true
   * from @{method:requireAuthentication}, based on the ##~/.arcrc## and
   * ##.arcconf## files if they are present. Thus, you can generally upgrade a
   * workflow which does not require authentication into an authenticated
   * workflow by later invoking @{method:requireAuthentication}. You should not
   * normally need to call this method unless you are specifically overriding
   * the defaults.
   *
   * NOTE: You can not call this method after calling
   * @{method:authenticateConduit}.
   *
   * @param dict  A credential dictionary, see @{method:authenticateConduit}.
   * @return this
   * @task conduit
   */
  final public function setConduitCredentials(array $credentials) {
    if ($this->isConduitAuthenticated()) {
      throw new Exception(
        "You may not set new credentials after authenticating conduit.");
    }

    $this->conduitCredentials = $credentials;
    return $this;
  }


  /**
   * Force arc to identify with a specific Conduit version during the
   * protocol handshake. This is primarily useful for development (especially
   * for sending diffs which bump the client Conduit version), since the client
   * still actually speaks the builtin version of the protocol.
   *
   * Controlled by the --conduit-version flag.
   *
   * @param int Version the client should pretend to be.
   * @return this
   * @task conduit
   */
  public function forceConduitVersion($version) {
    $this->forcedConduitVersion = $version;
    return $this;
  }


  /**
   * Get the protocol version the client should identify with.
   *
   * @return int Version the client should claim to be.
   * @task conduit
   */
  public function getConduitVersion() {
    return nonempty($this->forcedConduitVersion, 6);
  }


  /**
   * Override the default timeout for Conduit.
   *
   * Controlled by the --conduit-timeout flag.
   *
   * @param float Timeout, in seconds.
   * @return this
   * @task conduit
   */
  public function setConduitTimeout($timeout) {
    $this->conduitTimeout = $timeout;
    if ($this->conduit) {
      $this->conduit->setConduitTimeout($timeout);
    }
    return $this;
  }


  /**
   * Open and authenticate a conduit connection to a Phabricator server using
   * provided credentials. Normally, Arcanist does this for you automatically
   * when you return true from @{method:requiresAuthentication}, but you can
   * also upgrade an existing workflow to one with an authenticated conduit
   * by invoking this method manually.
   *
   * You must authenticate the conduit before you can make authenticated conduit
   * calls (almost all calls require authentication).
   *
   * This method uses credentials provided via @{method:setConduitCredentials}
   * to authenticate to the server:
   *
   *    - ##user## (required) The username to authenticate with.
   *    - ##certificate## (required) The Conduit certificate to use.
   *    - ##description## (optional) Description of the invoking command.
   *
   * Successful authentication allows you to call @{method:getUserPHID} and
   * @{method:getUserName}, as well as use the client you access with
   * @{method:getConduit} to make authenticated calls.
   *
   * NOTE: You must call @{method:setConduitURI} and
   * @{method:setConduitCredentials} before you invoke this method.
   *
   * @return this
   * @task conduit
   */
  final public function authenticateConduit() {
    if ($this->isConduitAuthenticated()) {
      return $this;
    }

    $this->establishConduit();
    $credentials = $this->conduitCredentials;

    try {
      if (!$credentials) {
        throw new Exception(
          "Set conduit credentials with setConduitCredentials() before ".
          "authenticating conduit!");
      }

      if (empty($credentials['user'])) {
        throw new ConduitClientException('ERR-INVALID-USER',
                                         'Empty user in credentials.');
      }
      if (empty($credentials['certificate'])) {
        throw new ConduitClientException('ERR-NO-CERTIFICATE',
                                         'Empty certificate in credentials.');
      }

      $description = idx($credentials, 'description', '');
      $user        = $credentials['user'];
      $certificate = $credentials['certificate'];

      $connection = $this->getConduit()->callMethodSynchronous(
        'conduit.connect',
        array(
          'client'              => 'arc',
          'clientVersion'       => $this->getConduitVersion(),
          'clientDescription'   => php_uname('n').':'.$description,
          'user'                => $user,
          'certificate'         => $certificate,
          'host'                => $this->conduitURI,
        ));
    } catch (ConduitClientException $ex) {
      if ($ex->getErrorCode() == 'ERR-NO-CERTIFICATE' ||
          $ex->getErrorCode() == 'ERR-INVALID-USER') {
        $conduit_uri = $this->conduitURI;
        $message =
          "\n".
          phutil_console_format(
            "YOU NEED TO __INSTALL A CERTIFICATE__ TO LOGIN TO PHABRICATOR").
          "\n\n".
          phutil_console_format(
            "    To do this, run: **arc install-certificate**").
          "\n\n".
          "The server '{$conduit_uri}' rejected your request:".
          "\n".
          $ex->getMessage();
        throw new ArcanistUsageException($message);
      } else if ($ex->getErrorCode() == 'NEW-ARC-VERSION') {

        // Cleverly disguise this as being AWESOME!!!

        echo phutil_console_format("**New Version Available!**\n\n");
        echo phutil_console_wrap($ex->getMessage());
        echo "\n\n";
        echo "In most cases, arc can be upgraded automatically.\n";

        $ok = phutil_console_confirm(
          "Upgrade arc now?",
          $default_no = false);
        if (!$ok) {
          throw $ex;
        }

        $root = dirname(phutil_get_library_root('arcanist'));

        chdir($root);
        $err = phutil_passthru('%s upgrade', $root.'/bin/arc');
        if (!$err) {
          echo "\nTry running your arc command again.\n";
        }
        exit(1);
      } else {
        throw $ex;
      }
    }

    $this->userName = $user;
    $this->userPHID = $connection['userPHID'];

    $this->conduitAuthenticated = true;

    return $this;
  }

  /**
   * @return bool True if conduit is authenticated, false otherwise.
   * @task conduit
   */
  final protected function isConduitAuthenticated() {
    return (bool)$this->conduitAuthenticated;
  }


  /**
   * Override this to return true if your workflow requires a conduit channel.
   * Arc will build the channel for you before your workflow executes. This
   * implies that you only need an unauthenticated channel; if you need
   * authentication, override @{method:requiresAuthentication}.
   *
   * @return bool True if arc should build a conduit channel before running
   *              the workflow.
   * @task conduit
   */
  public function requiresConduit() {
    return false;
  }


  /**
   * Override this to return true if your workflow requires an authenticated
   * conduit channel. This implies that it requires a conduit. Arc will build
   * and authenticate the channel for you before the workflow executes.
   *
   * @return bool True if arc should build an authenticated conduit channel
   *              before running the workflow.
   * @task conduit
   */
  public function requiresAuthentication() {
    return false;
  }


  /**
   * Returns the PHID for the user once they've authenticated via Conduit.
   *
   * @return phid Authenticated user PHID.
   * @task conduit
   */
  final public function getUserPHID() {
    if (!$this->userPHID) {
      $workflow = get_class($this);
      throw new Exception(
        "This workflow ('{$workflow}') requires authentication, override ".
        "requiresAuthentication() to return true.");
    }
    return $this->userPHID;
  }

  /**
   * Deprecated. See @{method:getUserPHID}.
   *
   * @deprecated
   */
  final public function getUserGUID() {
    phutil_deprecated(
      'ArcanistBaseWorkflow::getUserGUID',
      'This method has been renamed to getUserPHID().');
    return $this->getUserPHID();
  }

  /**
   * Return the username for the user once they've authenticated via Conduit.
   *
   * @return string Authenticated username.
   * @task conduit
   */
  final public function getUserName() {
    return $this->userName;
  }


  /**
   * Get the established @{class@libphutil:ConduitClient} in order to make
   * Conduit method calls. Before the client is available it must be connected,
   * either implicitly by making @{method:requireConduit} or
   * @{method:requireAuthentication} return true, or explicitly by calling
   * @{method:establishConduit} or @{method:authenticateConduit}.
   *
   * @return @{class@libphutil:ConduitClient} Live conduit client.
   * @task conduit
   */
  final public function getConduit() {
    if (!$this->conduit) {
      $workflow = get_class($this);
      throw new Exception(
        "This workflow ('{$workflow}') requires a Conduit, override ".
        "requiresConduit() to return true.");
    }
    return $this->conduit;
  }


  public function setArcanistConfiguration(
    ArcanistConfiguration $arcanist_configuration) {

    $this->arcanistConfiguration = $arcanist_configuration;
    return $this;
  }

  public function getArcanistConfiguration() {
    return $this->arcanistConfiguration;
  }

  public function requiresWorkingCopy() {
    return false;
  }

  public function desiresWorkingCopy() {
    return false;
  }

  public function requiresRepositoryAPI() {
    return false;
  }

  public function desiresRepositoryAPI() {
    return false;
  }

  public function setCommand($command) {
    $this->command = $command;
    return $this;
  }

  public function getCommand() {
    return $this->command;
  }

  public function getArguments() {
    return array();
  }

  public function setWorkingDirectory($working_directory) {
    $this->workingDirectory = $working_directory;
    return $this;
  }

  public function getWorkingDirectory() {
    return $this->workingDirectory;
  }

  private function setParentWorkflow($parent_workflow) {
    $this->parentWorkflow = $parent_workflow;
    return $this;
  }

  protected function getParentWorkflow() {
    return $this->parentWorkflow;
  }

  public function buildChildWorkflow($command, array $argv) {
    $arc_config = $this->getArcanistConfiguration();
    $workflow = $arc_config->buildWorkflow($command);
    $workflow->setParentWorkflow($this);
    $workflow->setCommand($command);

    if ($this->repositoryAPI) {
      $workflow->setRepositoryAPI($this->repositoryAPI);
    }

    if ($this->userPHID) {
      $workflow->userPHID = $this->getUserPHID();
      $workflow->userName = $this->getUserName();
    }

    if ($this->conduit) {
      $workflow->conduit = $this->conduit;
    }

    if ($this->workingCopy) {
      $workflow->setWorkingCopy($this->workingCopy);
    }

    $workflow->setArcanistConfiguration($arc_config);

    $workflow->parseArguments(array_values($argv));

    return $workflow;
  }

  public function getArgument($key, $default = null) {
    return idx($this->arguments, $key, $default);
  }

  public function getPassedArguments() {
    return $this->passedArguments;
  }

  final public function getCompleteArgumentSpecification() {
    $spec = $this->getArguments();
    $arc_config = $this->getArcanistConfiguration();
    $command = $this->getCommand();
    $spec += $arc_config->getCustomArgumentsForCommand($command);
    return $spec;
  }

  public function parseArguments(array $args) {
    $this->passedArguments = $args;

    $spec = $this->getCompleteArgumentSpecification();

    $dict = array();

    $more_key = null;
    if (!empty($spec['*'])) {
      $more_key = $spec['*'];
      unset($spec['*']);
      $dict[$more_key] = array();
    }

    $short_to_long_map = array();
    foreach ($spec as $long => $options) {
      if (!empty($options['short'])) {
        $short_to_long_map[$options['short']] = $long;
      }
    }

    foreach ($spec as $long => $options) {
      if (!empty($options['repeat'])) {
        $dict[$long] = array();
      }
    }

    $more = array();
    for ($ii = 0; $ii < count($args); $ii++) {
      $arg = $args[$ii];
      $arg_name = null;
      $arg_key = null;
      if ($arg == '--') {
        $more = array_merge(
          $more,
          array_slice($args, $ii + 1));
        break;
      } else if (!strncmp($arg, '--', 2)) {
        $arg_key = substr($arg, 2);
        if (!array_key_exists($arg_key, $spec)) {
          throw new ArcanistUsageException(
            "Unknown argument '{$arg_key}'. Try 'arc help'.");
        }
      } else if (!strncmp($arg, '-', 1)) {
        $arg_key = substr($arg, 1);
        if (empty($short_to_long_map[$arg_key])) {
          throw new ArcanistUsageException(
            "Unknown argument '{$arg_key}'. Try 'arc help'.");
        }
        $arg_key = $short_to_long_map[$arg_key];
      } else {
        $more[] = $arg;
        continue;
      }

      $options = $spec[$arg_key];
      if (empty($options['param'])) {
        $dict[$arg_key] = true;
      } else {
        if ($ii == count($args) - 1) {
          throw new ArcanistUsageException(
            "Option '{$arg}' requires a parameter.");
        }
        if (!empty($options['repeat'])) {
          $dict[$arg_key][] = $args[$ii + 1];
        } else {
          $dict[$arg_key] = $args[$ii + 1];
        }
        $ii++;
      }
    }

    if ($more) {
      if ($more_key) {
        $dict[$more_key] = $more;
      } else {
        $example = reset($more);
        throw new ArcanistUsageException(
          "Unrecognized argument '{$example}'. Try 'arc help'.");
      }
    }

    foreach ($dict as $key => $value) {
      if (empty($spec[$key]['conflicts'])) {
        continue;
      }
      foreach ($spec[$key]['conflicts'] as $conflict => $more) {
        if (isset($dict[$conflict])) {
          if ($more) {
            $more = ': '.$more;
          } else {
            $more = '.';
          }
          // TODO: We'll always display these as long-form, when the user might
          // have typed them as short form.
          throw new ArcanistUsageException(
            "Arguments '--{$key}' and '--{$conflict}' are mutually exclusive".
            $more);
        }
      }
    }

    $this->arguments = $dict;

    $this->didParseArguments();

    return $this;
  }

  protected function didParseArguments() {
    // Override this to customize workflow argument behavior.
  }

  public function getWorkingCopy() {
    if (!$this->workingCopy) {
      $workflow = get_class($this);
      throw new Exception(
        "This workflow ('{$workflow}') requires a working copy, override ".
        "requiresWorkingCopy() to return true.");
    }
    return $this->workingCopy;
  }

  public function setWorkingCopy(
    ArcanistWorkingCopyIdentity $working_copy) {
    $this->workingCopy = $working_copy;
    return $this;
  }

  public function setRepositoryAPI($api) {
    $this->repositoryAPI = $api;
    return $this;
  }

  public function getRepositoryAPI() {
    if (!$this->repositoryAPI) {
      $workflow = get_class($this);
      throw new Exception(
        "This workflow ('{$workflow}') requires a Repository API, override ".
        "requiresRepositoryAPI() to return true.");
    }
    return $this->repositoryAPI;
  }

  protected function shouldRequireCleanUntrackedFiles() {
    return empty($this->arguments['allow-untracked']);
  }

  public function setCommitMode($mode) {
    $this->commitMode = $mode;
    return $this;
  }

  public function requireCleanWorkingCopy() {
    $api = $this->getRepositoryAPI();

    $must_commit = array();

    $working_copy_desc = phutil_console_format(
      "  Working copy: __%s__\n\n",
      $api->getPath());

    $untracked = $api->getUntrackedChanges();
    if ($this->shouldRequireCleanUntrackedFiles()) {

      if (!empty($untracked)) {
        echo "You have untracked files in this working copy.\n\n".
             $working_copy_desc.
             "  Untracked files in working copy:\n".
             "    ".implode("\n    ", $untracked)."\n\n";

        if ($api instanceof ArcanistGitAPI) {
          echo phutil_console_wrap(
            "Since you don't have '.gitignore' rules for these files and have ".
            "not listed them in '.git/info/exclude', you may have forgotten ".
            "to 'git add' them to your commit.\n");
        } else if ($api instanceof ArcanistSubversionAPI) {
          echo phutil_console_wrap(
            "Since you don't have 'svn:ignore' rules for these files, you may ".
            "have forgotten to 'svn add' them.\n");
        } else if ($api instanceof ArcanistMercurialAPI) {
          echo phutil_console_wrap(
            "Since you don't have '.hgignore' rules for these files, you ".
            "may have forgotten to 'hg add' them to your commit.\n");
        }

        if ($this->askForAdd()) {
          $api->addToCommit($untracked);
          $must_commit += array_flip($untracked);
        } else if ($this->commitMode == self::COMMIT_DISABLE) {
          $prompt = "Do you want to continue without adding these files?";
          if (!phutil_console_confirm($prompt, $default_no = false)) {
            throw new ArcanistUserAbortException();
          }
        }

      }
    }

    $incomplete = $api->getIncompleteChanges();
    if ($incomplete) {
      throw new ArcanistUsageException(
        "You have incompletely checked out directories in this working copy. ".
        "Fix them before proceeding.\n\n".
        $working_copy_desc.
        "  Incomplete directories in working copy:\n".
        "    ".implode("\n    ", $incomplete)."\n\n".
        "You can fix these paths by running 'svn update' on them.");
    }

    $conflicts = $api->getMergeConflicts();
    if ($conflicts) {
      throw new ArcanistUsageException(
        "You have merge conflicts in this working copy. Resolve merge ".
        "conflicts before proceeding.\n\n".
        $working_copy_desc.
        "  Conflicts in working copy:\n".
        "    ".implode("\n    ", $conflicts)."\n");
    }

    $unstaged = $api->getUnstagedChanges();
    if ($unstaged) {
      echo "You have unstaged changes in this working copy.\n\n".
        $working_copy_desc.
        "  Unstaged changes in working copy:\n".
        "    ".implode("\n    ", $unstaged)."\n";
      if ($this->askForAdd()) {
        $api->addToCommit($unstaged);
        $must_commit += array_flip($unstaged);
      } else {
        throw new ArcanistUsageException(
          "Stage and commit (or revert) them before proceeding.");
      }
    }

    $uncommitted = $api->getUncommittedChanges();
    foreach ($uncommitted as $key => $path) {
      if (array_key_exists($path, $must_commit)) {
        unset($uncommitted[$key]);
      }
    }
    if ($uncommitted) {
      echo "You have uncommitted changes in this working copy.\n\n".
        $working_copy_desc.
        "  Uncommitted changes in working copy:\n".
        "    ".implode("\n    ", $uncommitted)."\n";
      if ($this->askForAdd()) {
        $must_commit += array_flip($uncommitted);
      } else {
        throw new ArcanistUncommittedChangesException(
          "Commit (or revert) them before proceeding.");
      }
    }

    if ($must_commit) {
      if ($this->shouldAmend) {
        $commit = head($api->getLocalCommitInformation());
        $api->amendCommit($commit['message']);
      } else if ($api->supportsLocalCommits()) {
        $api->doCommit(self::AUTO_COMMIT_TITLE);
      }
    }
  }

  private function shouldAmend() {
    $api = $this->getRepositoryAPI();

    if ($this->isHistoryImmutable() || !$api->supportsAmend()) {
      return false;
    }

    $commits = $api->getLocalCommitInformation();
    if (!$commits) {
      return false;
    }
    $commit = reset($commits);

    $message = ArcanistDifferentialCommitMessage::newFromRawCorpus(
      $commit['message']);
    if ($message->getGitSVNBaseRevision()) {
      return false;
    }

    if ($api->getAuthor() != $commit['author']) {
      return false;
    }

    // TODO: Check commits since tracking branch. If empty then return false.

    $repository = $this->loadProjectRepository();
    if ($repository) {
      $callsign = $repository['callsign'];
      $known_commits = $this->getConduit()->callMethodSynchronous(
        'diffusion.getcommits',
        array('commits' => array('r'.$callsign.$commit['commit'])));
      if (ifilter($known_commits, 'error', $negate = true)) {
        return false;
      }
    }

    if (!$message->getRevisionID()) {
      return true;
    }

    $in_working_copy = $api->loadWorkingCopyDifferentialRevisions(
      $this->getConduit(),
      array(
        'authors' => array($this->getUserPHID()),
        'status' => 'status-open',
      ));
    if ($in_working_copy) {
      return true;
    }

    return false;
  }

  private function askForAdd() {
    if ($this->commitMode == self::COMMIT_DISABLE) {
      return false;
    }
    if ($this->shouldAmend === null) {
      $this->shouldAmend = $this->shouldAmend();
    }
    if ($this->commitMode == self::COMMIT_ENABLE) {
      return true;
    }
    if ($this->shouldAmend) {
      $prompt = "Do you want to amend these files to the commit?";
    } else {
      $prompt = "Do you want to add these files to the commit?";
    }
    return phutil_console_confirm($prompt);
  }

  protected function loadDiffBundleFromConduit(
    ConduitClient $conduit,
    $diff_id) {

    return $this->loadBundleFromConduit(
      $conduit,
      array(
      'diff_id' => $diff_id,
    ));
  }

  protected function loadRevisionBundleFromConduit(
    ConduitClient $conduit,
    $revision_id) {

    return $this->loadBundleFromConduit(
      $conduit,
      array(
      'revision_id' => $revision_id,
    ));
  }

  private function loadBundleFromConduit(
    ConduitClient $conduit,
    $params) {

    $future = $conduit->callMethod('differential.getdiff', $params);
    $diff = $future->resolve();

    $changes = array();
    foreach ($diff['changes'] as $changedict) {
      $changes[] = ArcanistDiffChange::newFromDictionary($changedict);
    }
    $bundle = ArcanistBundle::newFromChanges($changes);
    $bundle->setConduit($conduit);
    // since the conduit method has changes, assume that these fields
    // could be unset
    $bundle->setProjectID(idx($diff, 'projectName'));
    $bundle->setBaseRevision(idx($diff, 'sourceControlBaseRevision'));
    $bundle->setRevisionID(idx($diff, 'revisionID'));
    $bundle->setAuthorName(idx($diff, 'authorName'));
    $bundle->setAuthorEmail(idx($diff, 'authorEmail'));
    return $bundle;
  }

  /**
   * Return a list of lines changed by the current diff, or ##null## if the
   * change list is meaningless (for example, because the path is a directory
   * or binary file).
   *
   * @param string      Path within the repository.
   * @param string      Change selection mode (see ArcanistDiffHunk).
   * @return list|null  List of changed line numbers, or null to indicate that
   *                    the path is not a line-oriented text file.
   */
  protected function getChangedLines($path, $mode) {
    $repository_api = $this->getRepositoryAPI();
    $full_path = $repository_api->getPath($path);
    if (is_dir($full_path)) {
      return null;
    }

    if (!file_exists($full_path)) {
      return null;
    }

    $change = $this->getChange($path);

    if ($change->getFileType() !== ArcanistDiffChangeType::FILE_TEXT) {
      return null;
    }

    $lines = $change->getChangedLines($mode);
    return array_keys($lines);
  }

  protected function getChange($path) {
    $repository_api = $this->getRepositoryAPI();

    // TODO: Very gross
    $is_git = ($repository_api instanceof ArcanistGitAPI);
    $is_hg = ($repository_api instanceof ArcanistMercurialAPI);
    $is_svn = ($repository_api instanceof ArcanistSubversionAPI);

    if ($is_svn) {
      // NOTE: In SVN, we don't currently support a "get all local changes"
      // operation, so special case it.
      if (empty($this->changeCache[$path])) {
        $diff = $repository_api->getRawDiffText($path);
        $parser = new ArcanistDiffParser();
        $changes = $parser->parseDiff($diff);
        if (count($changes) != 1) {
          throw new Exception("Expected exactly one change.");
        }
        $this->changeCache[$path] = reset($changes);
      }
    } else if ($is_git || $is_hg) {
      if (empty($this->changeCache)) {
        $changes = $repository_api->getAllLocalChanges();
        foreach ($changes as $change) {
          $this->changeCache[$change->getCurrentPath()] = $change;
        }
      }
    } else {
      throw new Exception("Missing VCS support.");
    }

    if (empty($this->changeCache[$path])) {
      if ($is_git) {
        // This can legitimately occur under git if you make a change, "git
        // commit" it, and then revert the change in the working copy and run
        // "arc lint".
        $change = new ArcanistDiffChange();
        $change->setCurrentPath($path);
        return $change;
      } else {
        throw new Exception(
          "Trying to get change for unchanged path '{$path}'!");
      }
    }

    return $this->changeCache[$path];
  }

  final public function willRunWorkflow() {
    $spec = $this->getCompleteArgumentSpecification();
    foreach ($this->arguments as $arg => $value) {
      if (empty($spec[$arg])) {
        continue;
      }
      $options = $spec[$arg];
      if (!empty($options['supports'])) {
        $system_name = $this->getRepositoryAPI()->getSourceControlSystemName();
        if (!in_array($system_name, $options['supports'])) {
          $extended_info = null;
          if (!empty($options['nosupport'][$system_name])) {
            $extended_info = ' '.$options['nosupport'][$system_name];
          }
          throw new ArcanistUsageException(
            "Option '--{$arg}' is not supported under {$system_name}.".
            $extended_info);
        }
      }
    }
  }

  protected function normalizeRevisionID($revision_id) {
    return preg_replace('/^D/i', '', $revision_id);
  }

  protected function shouldShellComplete() {
    return true;
  }

  protected function getShellCompletions(array $argv) {
    return array();
  }

  protected function getSupportedRevisionControlSystems() {
    return array('any');
  }

  protected function getPassthruArgumentsAsMap($command) {
    $map = array();
    foreach ($this->getCompleteArgumentSpecification() as $key => $spec) {
      if (!empty($spec['passthru'][$command])) {
        if (isset($this->arguments[$key])) {
          $map[$key] = $this->arguments[$key];
        }
      }
    }
    return $map;
  }

  protected function getPassthruArgumentsAsArgv($command) {
    $spec = $this->getCompleteArgumentSpecification();
    $map = $this->getPassthruArgumentsAsMap($command);
    $argv = array();
    foreach ($map as $key => $value) {
      $argv[] = '--'.$key;
      if (!empty($spec[$key]['param'])) {
        $argv[] = $value;
      }
    }
    return $argv;
  }

  public static function getSystemArcConfigLocation() {
    if (phutil_is_windows()) {
      // this is a horrible place to put this, but there doesn't seem to be a
      // non-horrible place on Windows
      return Filesystem::resolvePath(
        'Phabricator/Arcanist/config',
        getenv('PROGRAMFILES'));
    } else {
      return '/etc/arcconfig';
    }
  }

  public static function readSystemArcConfig() {
    $system_config = array();
    $system_config_path = self::getSystemArcConfigLocation();
    if (Filesystem::pathExists($system_config_path)) {
      $file = Filesystem::readFile($system_config_path);
      if ($file) {
        $system_config = json_decode($file, true);
      }
    }
    return $system_config;
  }
  public static function getUserConfigurationFileLocation() {
    if (phutil_is_windows()) {
      return getenv('APPDATA').'/.arcrc';
    } else {
      return getenv('HOME').'/.arcrc';
    }
  }

  public static function readUserConfigurationFile() {
    $user_config = array();
    $user_config_path = self::getUserConfigurationFileLocation();
    if (Filesystem::pathExists($user_config_path)) {

      if (!phutil_is_windows()) {
        $mode = fileperms($user_config_path);
        if (!$mode) {
          throw new Exception("Unable to get perms of '{$user_config_path}'!");
        }
        if ($mode & 0177) {
          // Mode should allow only owner access.
          $prompt = "File permissions on your ~/.arcrc are too open. ".
                    "Fix them by chmod'ing to 600?";
          if (!phutil_console_confirm($prompt, $default_no = false)) {
            throw new ArcanistUsageException("Set ~/.arcrc to file mode 600.");
          }
          execx('chmod 600 %s', $user_config_path);

          // Drop the stat cache so we don't read the old permissions if
          // we end up here again. If we don't do this, we may prompt the user
          // to fix permissions multiple times.
          clearstatcache();
        }
      }

      $user_config_data = Filesystem::readFile($user_config_path);
      $user_config = json_decode($user_config_data, true);
      if (!is_array($user_config)) {
        throw new ArcanistUsageException(
          "Your '~/.arcrc' file is not a valid JSON file.");
      }
    }
    return $user_config;
  }


  public static function writeUserConfigurationFile($config) {
    $json_encoder = new PhutilJSON();
    $json = $json_encoder->encodeFormatted($config);

    $path = self::getUserConfigurationFileLocation();
    Filesystem::writeFile($path, $json);

    if (!phutil_is_windows()) {
      execx('chmod 600 %s', $path);
    }
  }

  public static function readGlobalArcConfig() {
    return idx(self::readUserConfigurationFile(), 'config', array());
  }

  public static function writeGlobalArcConfig(array $options) {
    $config = self::readUserConfigurationFile();
    $config['config'] = $options;
    self::writeUserConfigurationFile($config);
  }

  public function readLocalArcConfig() {
    $local = array();
    $file = $this->readScratchFile('config');
    if ($file) {
      $local = json_decode($file, true);
    }

    return $local;
  }

  public function writeLocalArcConfig(array $config) {
    $json_encoder = new PhutilJSON();
    $json = $json_encoder->encodeFormatted($config);

    $this->writeScratchFile('config', $json);

    return $this;
  }


  /**
   * Write a message to stderr so that '--json' flags or stdout which is meant
   * to be piped somewhere aren't disrupted.
   *
   * @param string  Message to write to stderr.
   * @return void
   */
  protected function writeStatusMessage($msg) {
    fwrite(STDERR, $msg);
  }

  protected function isHistoryImmutable() {
    $repository_api = $this->getRepositoryAPI();
    $working_copy = $this->getWorkingCopy();

    $config = $working_copy->getConfigFromAnySource('history.immutable');
    if ($config !== null) {
      return $config;
    }

    return $repository_api->isHistoryDefaultImmutable();
  }

  /**
   * Workflows like 'lint' and 'unit' operate on a list of working copy paths.
   * The user can either specify the paths explicitly ("a.js b.php"), or by
   * specfifying a revision ("--rev a3f10f1f") to select all paths modified
   * since that revision, or by omitting both and letting arc choose the
   * default relative revision.
   *
   * This method takes the user's selections and returns the paths that the
   * workflow should act upon.
   *
   * @param   list          List of explicitly provided paths.
   * @param   string|null   Revision name, if provided.
   * @param   mask          Mask of ArcanistRepositoryAPI flags to exclude.
   *                        Defaults to ArcanistRepositoryAPI::FLAG_UNTRACKED.
   * @return  list          List of paths the workflow should act on.
   */
  protected function selectPathsForWorkflow(
    array $paths,
    $rev,
    $omit_mask = null) {

    if ($omit_mask === null) {
      $omit_mask = ArcanistRepositoryAPI::FLAG_UNTRACKED;
    }

    if ($paths) {
      $working_copy = $this->getWorkingCopy();
      foreach ($paths as $key => $path) {
        $full_path = Filesystem::resolvePath($path);
        if (!Filesystem::pathExists($full_path)) {
          throw new ArcanistUsageException("Path '{$path}' does not exist!");
        }
        $relative_path = Filesystem::readablePath(
          $full_path,
          $working_copy->getProjectRoot());
        $paths[$key] = $relative_path;
      }
    } else {
      $repository_api = $this->getRepositoryAPI();

      if ($rev) {
        $this->parseBaseCommitArgument(array($rev));
      }

      $paths = $repository_api->getWorkingCopyStatus();
      foreach ($paths as $path => $flags) {
        if ($flags & $omit_mask) {
          unset($paths[$path]);
        }
      }
      $paths = array_keys($paths);
    }

    return array_values($paths);
  }

  protected function renderRevisionList(array $revisions) {
    $list = array();
    foreach ($revisions as $revision) {
      $list[] = '     - D'.$revision['id'].': '.$revision['title']."\n";
    }
    return implode('', $list);
  }


/* -(  Scratch Files  )------------------------------------------------------ */


  /**
   * Try to read a scratch file, if it exists and is readable.
   *
   * @param string Scratch file name.
   * @return mixed String for file contents, or false for failure.
   * @task scratch
   */
  protected function readScratchFile($path) {
    if (!$this->repositoryAPI) {
      return false;
    }
    return $this->getRepositoryAPI()->readScratchFile($path);
  }


  /**
   * Try to read a scratch JSON file, if it exists and is readable.
   *
   * @param string Scratch file name.
   * @return array Empty array for failure.
   * @task scratch
   */
  protected function readScratchJSONFile($path) {
    $file = $this->readScratchFile($path);
    if (!$file) {
      return array();
    }
    return json_decode($file, true);
  }


  /**
   * Try to write a scratch file, if there's somewhere to put it and we can
   * write there.
   *
   * @param  string Scratch file name to write.
   * @param  string Data to write.
   * @return bool   True on success, false on failure.
   * @task scratch
   */
  protected function writeScratchFile($path, $data) {
    if (!$this->repositoryAPI) {
      return false;
    }
    return $this->getRepositoryAPI()->writeScratchFile($path, $data);
  }


  /**
   * Try to write a scratch JSON file, if there's somewhere to put it and we can
   * write there.
   *
   * @param  string Scratch file name to write.
   * @param  array Data to write.
   * @return bool   True on success, false on failure.
   * @task scratch
   */
  protected function writeScratchJSONFile($path, array $data) {
    return $this->writeScratchFile($path, json_encode($data));
  }


  /**
   * Try to remove a scratch file.
   *
   * @param   string  Scratch file name to remove.
   * @return  bool    True if the file was removed successfully.
   * @task scratch
   */
  protected function removeScratchFile($path) {
    if (!$this->repositoryAPI) {
      return false;
    }
    return $this->getRepositoryAPI()->removeScratchFile($path);
  }


  /**
   * Get a human-readable description of the scratch file location.
   *
   * @param string  Scratch file name.
   * @return mixed  String, or false on failure.
   * @task scratch
   */
  protected function getReadableScratchFilePath($path) {
    if (!$this->repositoryAPI) {
      return false;
    }
    return $this->getRepositoryAPI()->getReadableScratchFilePath($path);
  }


  /**
   * Get the path to a scratch file, if possible.
   *
   * @param string  Scratch file name.
   * @return mixed  File path, or false on failure.
   * @task scratch
   */
  protected function getScratchFilePath($path) {
    if (!$this->repositoryAPI) {
      return false;
    }
    return $this->getRepositoryAPI()->getScratchFilePath($path);
  }

  protected function getRepositoryEncoding() {
    $default = 'UTF-8';
    return nonempty(idx($this->getProjectInfo(), 'encoding'), $default);
  }

  protected function getProjectInfo() {
    if ($this->projectInfo === null) {
      $project_id = $this->getWorkingCopy()->getProjectID();
      if (!$project_id) {
        $this->projectInfo = array();
      } else {
        try {
          $this->projectInfo = $this->getConduit()->callMethodSynchronous(
            'arcanist.projectinfo',
            array(
              'name' => $project_id,
            ));
        } catch (ConduitClientException $ex) {
          if ($ex->getErrorCode() != 'ERR-BAD-ARCANIST-PROJECT') {
            throw $ex;
          }

          // TODO: Implement a proper query method that doesn't throw on
          // project not found. We just swallow this because some pathways,
          // like Git with uncommitted changes in a repository with a new
          // project ID, may attempt to access project information before
          // the project is created. See T2153.
          return array();
        }
      }
    }

    return $this->projectInfo;
  }

  protected function loadProjectRepository() {
    $project = $this->getProjectInfo();
    if (isset($project['repository'])) {
      return $project['repository'];
    }
    // NOTE: The rest of the code is here for backwards compatibility.

    $repository_phid = idx($project, 'repositoryPHID');
    if (!$repository_phid) {
      return array();
    }

    $repositories = $this->getConduit()->callMethodSynchronous(
      'repository.query',
      array());
    $repositories = ipull($repositories, null, 'phid');

    return idx($repositories, $repository_phid, array());
  }

  protected function newInteractiveEditor($text) {
    $editor = new PhutilInteractiveEditor($text);

    $preferred = $this->getWorkingCopy()->getConfigFromAnySource('editor');
    if ($preferred) {
      $editor->setPreferredEditor($preferred);
    }

    return $editor;
  }

  protected function newDiffParser() {
    $parser = new ArcanistDiffParser();
    if ($this->repositoryAPI) {
      $parser->setRepositoryAPI($this->getRepositoryAPI());
    }
    $parser->setWriteDiffOnFailure(true);
    return $parser;
  }

  protected function resolveCall(ConduitFuture $method, $timeout = null) {
    try {
      return $method->resolve($timeout);
    } catch (ConduitClientException $ex) {
      if ($ex->getErrorCode() == 'ERR-CONDUIT-CALL') {
        echo phutil_console_wrap(
          "This feature requires a newer version of Phabricator. Please ".
          "update it using these instructions: ".
          "http://www.phabricator.com/docs/phabricator/article/".
          "Installation_Guide.html#updating-phabricator\n\n");
      }
      throw $ex;
    }
  }

  protected function dispatchEvent($type, array $data) {
    $data += array(
      'workflow' => $this,
    );

    $event = new PhutilEvent($type, $data);
    PhutilEventEngine::dispatchEvent($event);

    return $event;
  }

  public function parseBaseCommitArgument(array $argv) {
    if (!count($argv)) {
      return;
    }

    $api = $this->getRepositoryAPI();
    if (!$api->supportsCommitRanges()) {
      throw new ArcanistUsageException(
        "This version control system does not support commit ranges.");
    }

    if (count($argv) > 1) {
      throw new ArcanistUsageException(
        "Specify exactly one base commit. The end of the commit range is ".
        "always the working copy state.");
    }

    $api->setBaseCommit(head($argv));

    return $this;
  }

  protected function getRepositoryVersion() {
    if (!$this->repositoryVersion) {
      $api = $this->getRepositoryAPI();
      $commit = $api->getSourceControlBaseRevision();
      $versions = array('' => $commit);
      foreach ($api->getChangedFiles($commit) as $path => $mask) {
        $versions[$path] = (Filesystem::pathExists($path)
          ? md5_file($path)
          : '');
      }
      $this->repositoryVersion = md5(json_encode($versions));
    }
    return $this->repositoryVersion;
  }

}
