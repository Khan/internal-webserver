<?php

/**
 * Interfaces with the VCS in the working copy.
 *
 * @task  status      Path Status
 * @group workingcopy
 */
abstract class ArcanistRepositoryAPI {

  const FLAG_MODIFIED     = 1;
  const FLAG_ADDED        = 2;
  const FLAG_DELETED      = 4;
  const FLAG_UNTRACKED    = 8;
  const FLAG_CONFLICT     = 16;
  const FLAG_MISSING      = 32;
  const FLAG_UNSTAGED     = 64;
  const FLAG_UNCOMMITTED  = 128;
  const FLAG_EXTERNALS    = 256;

  // Occurs in SVN when you replace a file with a directory without telling
  // SVN about it.
  const FLAG_OBSTRUCTED   = 512;

  // Occurs in SVN when an update was interrupted or failed, e.g. you ^C'd it.
  const FLAG_INCOMPLETE   = 1024;

  protected $path;
  protected $diffLinesOfContext = 0x7FFF;
  private $baseCommitExplanation = '???';
  private $workingCopyIdentity;
  private $baseCommitArgumentRules;

  private $uncommittedStatusCache;
  private $commitRangeStatusCache;

  private $symbolicBaseCommit;
  private $resolvedBaseCommit;

  abstract public function getSourceControlSystemName();

  public function getDiffLinesOfContext() {
    return $this->diffLinesOfContext;
  }

  public function setDiffLinesOfContext($lines) {
    $this->diffLinesOfContext = $lines;
    return $this;
  }

  public function getWorkingCopyIdentity() {
    return $this->workingCopyIdentity;
  }

  public static function newAPIFromWorkingCopyIdentity(
    ArcanistWorkingCopyIdentity $working_copy) {

    $root = $working_copy->getProjectRoot();

    if (!$root) {
      throw new ArcanistUsageException(
        "There is no readable '.arcconfig' file in the working directory or ".
        "any parent directory. Create an '.arcconfig' file to configure arc.");
    }

    if (Filesystem::pathExists($root.'/.hg')) {
      $api = new ArcanistMercurialAPI($root);
      $api->workingCopyIdentity = $working_copy;
      return $api;
    }

    $git_root = self::discoverGitBaseDirectory($root);
    if ($git_root) {
      if (!Filesystem::pathsAreEquivalent($root, $git_root)) {
        throw new ArcanistUsageException(
          "'.arcconfig' file is located at '{$root}', but working copy root ".
          "is '{$git_root}'. Move '.arcconfig' file to the working copy root.");
      }

      $api = new ArcanistGitAPI($root);
      $api->workingCopyIdentity = $working_copy;
      return $api;
    }

    // check if we're in an svn working copy
    foreach (Filesystem::walkToRoot($root) as $dir) {
      if (Filesystem::pathExists($dir . '/.svn')) {
        $api = new ArcanistSubversionAPI($root);
        $api->workingCopyIdentity = $working_copy;
        return $api;
      }
    }

    throw new ArcanistUsageException(
      "The current working directory is not part of a working copy for a ".
      "supported version control system (svn, git or mercurial).");
  }

  public function __construct($path) {
    $this->path = $path;
  }

  public function getPath($to_file = null) {
    if ($to_file !== null) {
      return $this->path.DIRECTORY_SEPARATOR.
             ltrim($to_file, DIRECTORY_SEPARATOR);
    } else {
      return $this->path.DIRECTORY_SEPARATOR;
    }
  }


/* -(  Path Status  )-------------------------------------------------------- */


  abstract protected function buildUncommittedStatus();
  abstract protected function buildCommitRangeStatus();


  /**
   * Get a list of uncommitted paths in the working copy that have been changed
   * or are affected by other status effects, like conflicts or untracked
   * files.
   *
   * Convenience methods @{method:getUntrackedChanges},
   * @{method:getUnstagedChanges}, @{method:getUncommittedChanges},
   * @{method:getMergeConflicts}, and @{method:getIncompleteChanges} allow
   * simpler selection of paths in a specific state.
   *
   * This method returns a map of paths to bitmasks with status, using
   * `FLAG_` constants. For example:
   *
   *   array(
   *     'some/uncommitted/file.txt' => ArcanistRepositoryAPI::FLAG_UNSTAGED,
   *   );
   *
   * A file may be in several states. Not all states are possible with all
   * version control systems.
   *
   * @return map<string, bitmask> Map of paths, see above.
   * @task status
   */
  final public function getUncommittedStatus() {
    if ($this->uncommittedStatusCache === null) {
      $status = $this->buildUncommittedStatus();
      ksort($status);
      $this->uncommittedStatusCache = $status;
    }
    return $this->uncommittedStatusCache;
  }


  /**
   * @task status
   */
  final public function getUntrackedChanges() {
    return $this->getUncommittedPathsWithMask(self::FLAG_UNTRACKED);
  }


  /**
   * @task status
   */
  final public function getUnstagedChanges() {
    return $this->getUncommittedPathsWithMask(self::FLAG_UNSTAGED);
  }


  /**
   * @task status
   */
  final public function getUncommittedChanges() {
    return $this->getUncommittedPathsWithMask(self::FLAG_UNCOMMITTED);
  }


  /**
   * @task status
   */
  final public function getMergeConflicts() {
    return $this->getUncommittedPathsWithMask(self::FLAG_CONFLICT);
  }


  /**
   * @task status
   */
  final public function getIncompleteChanges() {
    return $this->getUncommittedPathsWithMask(self::FLAG_INCOMPLETE);
  }


  /**
   * @task status
   */
  private function getUncommittedPathsWithMask($mask) {
    $match = array();
    foreach ($this->getUncommittedStatus() as $path => $flags) {
      if ($flags & $mask) {
        $match[] = $path;
      }
    }
    return $match;
  }


  /**
   * Get a list of paths affected by the commits in the current commit range.
   *
   * See @{method:getUncommittedStatus} for a description of the return value.
   *
   * @return map<string, bitmask> Map from paths to status.
   * @task status
   */
  final public function getCommitRangeStatus() {
    if ($this->commitRangeStatusCache === null) {
      $status = $this->buildCommitRangeStatus();
      ksort($status);
      $this->commitRangeStatusCache = $status;
    }
    return $this->commitRangeStatusCache;
  }


  /**
   * Get a list of paths affected by commits in the current commit range, or
   * uncommitted changes in the working copy. See @{method:getUncommittedStatus}
   * or @{method:getCommitRangeStatus} to retreive smaller parts of the status.
   *
   * See @{method:getUncommittedStatus} for a description of the return value.
   *
   * @return map<string, bitmask> Map from paths to status.
   * @task status
   */
  final public function getWorkingCopyStatus() {
    $range_status = $this->getCommitRangeStatus();
    $uncommitted_status = $this->getUncommittedStatus();

    $result = new PhutilArrayWithDefaultValue($range_status);
    foreach ($uncommitted_status as $path => $mask) {
      $result[$path] |= $mask;
    }

    $result = $result->toArray();
    ksort($result);
    return $result;
  }


  /**
   * Drops caches after changes to the working copy. By default, some queries
   * against the working copy are cached. They
   *
   * @return this
   * @task status
   */
  final public function reloadWorkingCopy() {
    $this->uncommittedStatusCache = null;
    $this->commitRangeStatusCache = null;

    $this->didReloadWorkingCopy();
    $this->reloadCommitRange();

    return $this;
  }


  /**
   * Hook for implementations to dirty working copy caches after the working
   * copy has been updated.
   *
   * @return this
   * @task status
   */
  protected function didReloadWorkingCopy() {
    return;
  }



  private static function discoverGitBaseDirectory($root) {
    try {

      // NOTE: This awkward construction is to make sure things work on Windows.
      $future = new ExecFuture('git rev-parse --show-cdup');
      $future->setCWD($root);
      list($stdout) = $future->resolvex();

      return Filesystem::resolvePath(rtrim($stdout, "\n"), $root);
    } catch (CommandException $ex) {
      // This might be because the $root isn't a Git working copy, or the user
      // might not have Git installed at all so the `git` command fails. Assume
      // that users trying to work with git working copies will have a working
      // `git` binary.
      return null;
    }
  }

  /**
   * Fetches the original file data for each path provided.
   *
   * @return map<string, string> Map from path to file data.
   */
  public function getBulkOriginalFileData($paths) {
    $filedata = array();
    foreach ($paths as $path) {
      $filedata[$path] = $this->getOriginalFileData($path);
    }

    return $filedata;
  }

  /**
   * Fetches the current file data for each path provided.
   *
   * @return map<string, string> Map from path to file data.
   */
  public function getBulkCurrentFileData($paths) {
    $filedata = array();
    foreach ($paths as $path) {
      $filedata[$path] = $this->getCurrentFileData($path);
    }

    return $filedata;
  }

  /**
   * @return Traversable
   */
  abstract public function getAllFiles();

  abstract public function getBlame($path);

  abstract public function getRawDiffText($path);
  abstract public function getOriginalFileData($path);
  abstract public function getCurrentFileData($path);
  abstract public function getLocalCommitInformation();
  abstract public function getSourceControlBaseRevision();
  abstract public function getCanonicalRevisionName($string);
  abstract public function getBranchName();
  abstract public function getSourceControlPath();
  abstract public function isHistoryDefaultImmutable();
  abstract public function supportsAmend();
  abstract public function getWorkingCopyRevision();
  abstract public function updateWorkingCopy();
  abstract public function getMetadataPath();
  abstract public function loadWorkingCopyDifferentialRevisions(
    ConduitClient $conduit,
    array $query);

  public function getUnderlyingWorkingCopyRevision() {
    return $this->getWorkingCopyRevision();
  }

  public function getChangedFiles($since_commit) {
    throw new ArcanistCapabilityNotSupportedException($this);
  }

  public function getAuthor() {
    throw new ArcanistCapabilityNotSupportedException($this);
  }

  public function addToCommit(array $paths) {
    throw new ArcanistCapabilityNotSupportedException($this);
  }

  abstract public function supportsLocalCommits();

  public function doCommit($message) {
    throw new ArcanistCapabilityNotSupportedException($this);
  }

  public function amendCommit($message = null) {
    throw new ArcanistCapabilityNotSupportedException($this);
  }

  public function getAllBranches() {
    // TODO: Implement for Mercurial/SVN and make abstract.
    return array();
  }

  public function hasLocalCommit($commit) {
    throw new ArcanistCapabilityNotSupportedException($this);
  }

  public function getCommitMessage($commit) {
    throw new ArcanistCapabilityNotSupportedException($this);
  }

  public function getCommitSummary($commit) {
    throw new ArcanistCapabilityNotSupportedException($this);
  }

  public function getAllLocalChanges() {
    throw new ArcanistCapabilityNotSupportedException($this);
  }

  abstract public function supportsLocalBranchMerge();

  public function performLocalBranchMerge($branch, $message) {
    throw new ArcanistCapabilityNotSupportedException($this);
  }

  public function getFinalizedRevisionMessage() {
    throw new ArcanistCapabilityNotSupportedException($this);
  }

  public function execxLocal($pattern /* , ... */) {
    $args = func_get_args();
    return $this->buildLocalFuture($args)->resolvex();
  }

  public function execManualLocal($pattern /* , ... */) {
    $args = func_get_args();
    return $this->buildLocalFuture($args)->resolve();
  }

  public function execFutureLocal($pattern /* , ... */) {
    $args = func_get_args();
    return $this->buildLocalFuture($args);
  }

  abstract protected function buildLocalFuture(array $argv);

  public function canStashChanges() {
    return false;
  }

  public function stashChanges() {
    throw new ArcanistCapabilityNotSupportedException($this);
  }

  public function unstashChanges() {
    throw new ArcanistCapabilityNotSupportedException($this);
  }

/* -(  Scratch Files  )------------------------------------------------------ */


  /**
   * Try to read a scratch file, if it exists and is readable.
   *
   * @param string Scratch file name.
   * @return mixed String for file contents, or false for failure.
   * @task scratch
   */
  public function readScratchFile($path) {
    $full_path = $this->getScratchFilePath($path);
    if (!$full_path) {
      return false;
    }

    if (!Filesystem::pathExists($full_path)) {
      return false;
    }

    try {
      $result = Filesystem::readFile($full_path);
    } catch (FilesystemException $ex) {
      return false;
    }

    return $result;
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
  public function writeScratchFile($path, $data) {
    $dir = $this->getScratchFilePath('');
    if (!$dir) {
      return false;
    }

    if (!Filesystem::pathExists($dir)) {
      try {
        Filesystem::createDirectory($dir);
      } catch (Exception $ex) {
        return false;
      }
    }

    try {
      Filesystem::writeFile($this->getScratchFilePath($path), $data);
    } catch (FilesystemException $ex) {
      return false;
    }

    return true;
  }


  /**
   * Try to remove a scratch file.
   *
   * @param   string  Scratch file name to remove.
   * @return  bool    True if the file was removed successfully.
   * @task scratch
   */
  public function removeScratchFile($path) {
    $full_path = $this->getScratchFilePath($path);
    if (!$full_path) {
      return false;
    }

    try {
      Filesystem::remove($full_path);
    } catch (FilesystemException $ex) {
      return false;
    }

    return true;
  }


  /**
   * Get a human-readable description of the scratch file location.
   *
   * @param string  Scratch file name.
   * @return mixed  String, or false on failure.
   * @task scratch
   */
  public function getReadableScratchFilePath($path) {
    $full_path = $this->getScratchFilePath($path);
    if ($full_path) {
      return Filesystem::readablePath(
        $full_path,
        $this->getPath());
    } else {
      return false;
    }
  }


  /**
   * Get the path to a scratch file, if possible.
   *
   * @param string  Scratch file name.
   * @return mixed  File path, or false on failure.
   * @task scratch
   */
  public function getScratchFilePath($path) {
    $new_scratch_path  = Filesystem::resolvePath(
      'arc',
      $this->getMetadataPath());

    static $checked = false;
    if (!$checked) {
      $checked = true;
      $old_scratch_path = $this->getPath('.arc');
      // we only want to do the migration once
      // unfortunately, people have checked in .arc directories which
      // means that the old one may get recreated after we delete it
      if (Filesystem::pathExists($old_scratch_path) &&
          !Filesystem::pathExists($new_scratch_path)) {
        Filesystem::createDirectory($new_scratch_path);
        $existing_files = Filesystem::listDirectory($old_scratch_path, true);
        foreach ($existing_files as $file) {
          $new_path = Filesystem::resolvePath($file, $new_scratch_path);
          $old_path = Filesystem::resolvePath($file, $old_scratch_path);
          Filesystem::writeFile(
            $new_path,
            Filesystem::readFile($old_path));
        }
        Filesystem::remove($old_scratch_path);
      }
    }
    return Filesystem::resolvePath($path, $new_scratch_path);
  }


/* -(  Base Commits  )------------------------------------------------------- */

  abstract public function supportsCommitRanges();

  final public function setBaseCommit($symbolic_commit) {
    if (!$this->supportsCommitRanges()) {
      throw new ArcanistCapabilityNotSupportedException($this);
    }

    $this->symbolicBaseCommit = $symbolic_commit;
    $this->reloadCommitRange();
    return $this;
  }

  final public function getBaseCommit() {
    if (!$this->supportsCommitRanges()) {
      throw new ArcanistCapabilityNotSupportedException($this);
    }

    if ($this->resolvedBaseCommit === null) {
      $commit = $this->buildBaseCommit($this->symbolicBaseCommit);
      $this->resolvedBaseCommit = $commit;
    }

    return $this->resolvedBaseCommit;
  }

  final public function reloadCommitRange() {
    $this->resolvedBaseCommit = null;
    $this->baseCommitExplanation = null;

    $this->didReloadCommitRange();

    return $this;
  }

  protected function didReloadCommitRange() {
    return;
  }

  protected function buildBaseCommit($symbolic_commit) {
    throw new ArcanistCapabilityNotSupportedException($this);
  }

  public function getBaseCommitExplanation() {
    return $this->baseCommitExplanation;
  }

  public function setBaseCommitExplanation($explanation) {
    $this->baseCommitExplanation = $explanation;
    return $this;
  }

  public function resolveBaseCommitRule($rule, $source) {
    return null;
  }

  public function setBaseCommitArgumentRules($base_commit_argument_rules) {
    $this->baseCommitArgumentRules = $base_commit_argument_rules;
    return $this;
  }

  public function getBaseCommitArgumentRules() {
    return $this->baseCommitArgumentRules;
  }

  public function resolveBaseCommit() {
    $working_copy = $this->getWorkingCopyIdentity();
    $global_config = ArcanistBaseWorkflow::readGlobalArcConfig();
    $system_config = ArcanistBaseWorkflow::readSystemArcConfig();

    $parser = new ArcanistBaseCommitParser($this);
    $commit = $parser->resolveBaseCommit(
      array(
        'args'    => $this->getBaseCommitArgumentRules(),
        'local'   => $working_copy->getLocalConfig('base', ''),
        'project' => $working_copy->getConfig('base', ''),
        'global'  => idx($global_config, 'base', ''),
        'system'  => idx($system_config, 'base', ''),
      ));

    return $commit;
  }

}
