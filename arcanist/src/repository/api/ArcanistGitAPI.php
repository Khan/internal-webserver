<?php

/**
 * Interfaces with Git working copies.
 *
 * @group workingcopy
 */
final class ArcanistGitAPI extends ArcanistRepositoryAPI {

  private $repositoryHasNoCommits = false;
  const SEARCH_LENGTH_FOR_PARENT_REVISIONS = 16;

  /**
   * For the repository's initial commit, 'git diff HEAD^' and similar do
   * not work. Using this instead does work; it is the hash of the empty tree.
   */
  const GIT_MAGIC_ROOT_COMMIT = '4b825dc642cb6eb9a060e54bf8d69288fbee4904';

  public static function newHookAPI($root) {
    return new ArcanistGitAPI($root);
  }

  protected function buildLocalFuture(array $argv) {

    $argv[0] = 'git '.$argv[0];

    $future = newv('ExecFuture', $argv);
    $future->setCWD($this->getPath());
    return $future;
  }


  public function getSourceControlSystemName() {
    return 'git';
  }

  public function getMetadataPath() {
    static $path = null;
    if ($path === null) {
      list($stdout) = $this->execxLocal('rev-parse --git-dir');
      $path = rtrim($stdout, "\n");
      // the output of git rev-parse --git-dir is an absolute path, unless
      // the cwd is the root of the repository, in which case it uses the
      // relative path of .git. If we get this relative path, turn it into
      // an absolute path.
      if ($path === '.git') {
        $path = $this->getPath('.git');
      }
    }
    return $path;
  }

  public function getHasCommits() {
    return !$this->repositoryHasNoCommits;
  }

  public function getLocalCommitInformation() {
    if ($this->repositoryHasNoCommits) {
      // Zero commits.
      throw new Exception(
        "You can't get local commit information for a repository with no ".
        "commits.");
    } else if ($this->getBaseCommit() == self::GIT_MAGIC_ROOT_COMMIT) {
      // One commit.
      $against = 'HEAD';
    } else {

      // 2..N commits. We include commits reachable from HEAD which are
      // not reachable from the relative commit; this is consistent with
      // user expectations even though it is not actually the diff range.
      // Particularly:
      //
      //    |
      //    D <----- master branch
      //    |
      //    C  Y <- feature branch
      //    | /|
      //    B  X
      //    | /
      //    A
      //    |
      //
      // If "A, B, C, D" are master, and the user is at Y, when they run
      // "arc diff B" they want (and get) a diff of B vs Y, but they think about
      // this as being the commits X and Y. If we log "B..Y", we only show
      // Y. With "Y --not B", we show X and Y.

      $against = csprintf('%s --not %s', 'HEAD', $this->getBaseCommit());
    }

    // NOTE: Windows escaping of "%" symbols apparently is inherently broken;
    // when passed throuhgh escapeshellarg() they are replaced with spaces.

    // TODO: Learn how cmd.exe works and find some clever workaround?

    // NOTE: If we use "%x00", output is truncated in Windows.

    list($info) = $this->execxLocal(
      phutil_is_windows()
        ? 'log %C --format=%C --'
        : 'log %C --format=%s --',
      $against,
      // NOTE: "%B" is somewhat new, use "%s%n%n%b" instead.
      '%H%x01%T%x01%P%x01%at%x01%an%x01%aE%x01%s%x01%s%n%n%b%x02');

    $commits = array();

    $info = trim($info, " \n\2");
    if (!strlen($info)) {
      return array();
    }

    $info = explode("\2", $info);
    foreach ($info as $line) {
      list($commit, $tree, $parents, $time, $author, $author_email,
        $title, $message) = explode("\1", trim($line), 8);
      $message = rtrim($message);

      $commits[$commit] = array(
        'commit'  => $commit,
        'tree'    => $tree,
        'parents' => array_filter(explode(' ', $parents)),
        'time'    => $time,
        'author'  => $author,
        'summary' => $title,
        'message' => $message,
        'authorEmail' => $author_email,
      );
    }

    return $commits;
  }

  protected function buildBaseCommit($symbolic_commit) {
    if ($symbolic_commit !== null) {
      if ($symbolic_commit == ArcanistGitAPI::GIT_MAGIC_ROOT_COMMIT) {
        $this->setBaseCommitExplanation(
          "you explicitly specified the empty tree.");
        return $symbolic_commit;
      }

      list($err, $merge_base) = $this->execManualLocal(
        'merge-base %s HEAD',
        $symbolic_commit);
      if ($err) {
        throw new ArcanistUsageException(
          "Unable to find any git commit named '{$symbolic_commit}' in ".
          "this repository.");
      }

      $this->setBaseCommitExplanation(
        "it is the merge-base of '{$symbolic_commit}' and HEAD, as you ".
        "explicitly specified.");
      return trim($merge_base);
    }

    // Detect zero-commit or one-commit repositories. There is only one
    // relative-commit value that makes any sense in these repositories: the
    // empty tree.
    list($err) = $this->execManualLocal('rev-parse --verify HEAD^');
    if ($err) {
      list($err) = $this->execManualLocal('rev-parse --verify HEAD');
      if ($err) {
        $this->repositoryHasNoCommits = true;
      }

      if ($this->repositoryHasNoCommits) {
        $this->setBaseCommitExplanation(
          "the repository has no commits.");
      } else {
        $this->setBaseCommitExplanation(
          "the repository has only one commit.");
      }

      return self::GIT_MAGIC_ROOT_COMMIT;
    }

    if ($this->getBaseCommitArgumentRules() ||
        $this->getWorkingCopyIdentity()->getConfigFromAnySource('base')) {
      $base = $this->resolveBaseCommit();
      if (!$base) {
        throw new ArcanistUsageException(
          "None of the rules in your 'base' configuration matched a valid ".
          "commit. Adjust rules or specify which commit you want to use ".
          "explicitly.");
      }
      return $base;
    }

    $do_write = false;
    $default_relative = null;
    $working_copy = $this->getWorkingCopyIdentity();
    if ($working_copy) {
      $default_relative = $working_copy->getConfig(
        'git.default-relative-commit');
      $this->setBaseCommitExplanation(
        "it is the merge-base of '{$default_relative}' and HEAD, as ".
        "specified in 'git.default-relative-commit' in '.arcconfig'. This ".
        "setting overrides other settings.");
    }

    if (!$default_relative) {
      list($err, $upstream) = $this->execManualLocal(
        "rev-parse --abbrev-ref --symbolic-full-name '@{upstream}'");

      if (!$err) {
        $default_relative = trim($upstream);
        $this->setBaseCommitExplanation(
          "it is the merge-base of '{$default_relative}' (the Git upstream ".
          "of the current branch) HEAD.");
      }
    }

    if (!$default_relative) {
      $default_relative = $this->readScratchFile('default-relative-commit');
      $default_relative = trim($default_relative);
      if ($default_relative) {
        $this->setBaseCommitExplanation(
          "it is the merge-base of '{$default_relative}' and HEAD, as ".
          "specified in '.git/arc/default-relative-commit'.");
      }
    }

    if (!$default_relative) {

      // TODO: Remove the history lesson soon.

      echo phutil_console_format(
        "<bg:green>** Select a Default Commit Range **</bg>\n\n");
      echo phutil_console_wrap(
        "You're running a command which operates on a range of revisions ".
        "(usually, from some revision to HEAD) but have not specified the ".
        "revision that should determine the start of the range.\n\n".
        "Previously, arc assumed you meant 'HEAD^' when you did not specify ".
        "a start revision, but this behavior does not make much sense in ".
        "most workflows outside of Facebook's historic git-svn workflow.\n\n".
        "arc no longer assumes 'HEAD^'. You must specify a relative commit ".
        "explicitly when you invoke a command (e.g., `arc diff HEAD^`, not ".
        "just `arc diff`) or select a default for this working copy.\n\n".
        "In most cases, the best default is 'origin/master'. You can also ".
        "select 'HEAD^' to preserve the old behavior, or some other remote ".
        "or branch. But you almost certainly want to select ".
        "'origin/master'.\n\n".
        "(Technically: the merge-base of the selected revision and HEAD is ".
        "used to determine the start of the commit range.)");

      $prompt = "What default do you want to use? [origin/master]";
      $default = phutil_console_prompt($prompt);

      if (!strlen(trim($default))) {
        $default = 'origin/master';
      }

      $default_relative = $default;
      $do_write = true;
    }

    list($object_type) = $this->execxLocal(
      'cat-file -t %s',
      $default_relative);

    if (trim($object_type) !== 'commit') {
      throw new Exception(
        "Relative commit '{$default_relative}' is not the name of a commit!");
    }

    if ($do_write) {
      // Don't perform this write until we've verified that the object is a
      // valid commit name.
      $this->writeScratchFile('default-relative-commit', $default_relative);
      $this->setBaseCommitExplanation(
        "it is the merge-base of '{$default_relative}' and HEAD, as you ".
        "just specified.");
    }

    list($merge_base) = $this->execxLocal(
      'merge-base %s HEAD',
      $default_relative);

    return trim($merge_base);
  }

  private function getDiffFullOptions($detect_moves_and_renames = true) {
    $options = array(
      self::getDiffBaseOptions(),
      '--no-color',
      '--src-prefix=a/',
      '--dst-prefix=b/',
      '-U'.$this->getDiffLinesOfContext(),
    );

    if ($detect_moves_and_renames) {
      $options[] = '-M';
      $options[] = '-C';
    }

    return implode(' ', $options);
  }

  private function getDiffBaseOptions() {
    $options = array(
      // Disable external diff drivers, like graphical differs, since Arcanist
      // needs to capture the diff text.
      '--no-ext-diff',
      // Disable textconv so we treat binary files as binary, even if they have
      // an alternative textual representation. TODO: Ideally, Differential
      // would ship up the binaries for 'arc patch' but display the textconv
      // output in the visual diff.
      '--no-textconv',
    );
    return implode(' ', $options);
  }

  public function getFullGitDiff() {
    $options = $this->getDiffFullOptions();
    list($stdout) = $this->execxLocal(
      "diff {$options} %s --",
      $this->getBaseCommit());
    return $stdout;
  }

  /**
   * @param string Path to generate a diff for.
   * @param bool   If true, detect moves and renames. Otherwise, ignore
   *               moves/renames; this is useful because it prompts git to
   *               generate real diff text.
   */
  public function getRawDiffText($path, $detect_moves_and_renames = true) {
    $options = $this->getDiffFullOptions($detect_moves_and_renames);
    list($stdout) = $this->execxLocal(
      "diff {$options} %s -- %s",
      $this->getBaseCommit(),
      $path);
    return $stdout;
  }

  public function getBranchName() {
    // TODO: consider:
    //
    //    $ git rev-parse --abbrev-ref `git symbolic-ref HEAD`
    //
    // But that may fail if you're not on a branch.
    list($stdout) = $this->execxLocal('branch --no-color');

    // Assume that any branch beginning with '(' means 'no branch', or whatever
    // 'no branch' is in the current locale.
    $matches = null;
    if (preg_match('/^\* ([^\(].*)$/m', $stdout, $matches)) {
      return $matches[1];
    }
    return null;
  }

  public function getSourceControlPath() {
    // TODO: Try to get something useful here.
    return null;
  }

  public function getGitCommitLog() {
    $relative = $this->getBaseCommit();
    if ($this->repositoryHasNoCommits) {
      // No commits yet.
      return '';
    } else if ($relative == self::GIT_MAGIC_ROOT_COMMIT) {
      // First commit.
      list($stdout) = $this->execxLocal(
        'log --format=medium HEAD');
    } else {
      // 2..N commits.
      list($stdout) = $this->execxLocal(
        'log --first-parent --format=medium %s..HEAD',
        $this->getBaseCommit());
    }
    return $stdout;
  }

  public function getGitHistoryLog() {
    list($stdout) = $this->execxLocal(
      'log --format=medium -n%d %s',
      self::SEARCH_LENGTH_FOR_PARENT_REVISIONS,
      $this->getBaseCommit());
    return $stdout;
  }

  public function getSourceControlBaseRevision() {
    list($stdout) = $this->execxLocal(
      'rev-parse %s',
      $this->getBaseCommit());
    return rtrim($stdout, "\n");
  }

  public function getCanonicalRevisionName($string) {
    $match = null;
    if (preg_match('/@([0-9]+)$/', $string, $match)) {
      list($stdout) = $this->execxLocal(
        'svn find-rev r%d',
        $match[1]);
    } else {
      list($stdout) = $this->execxLocal(
        'show -s --format=%C %s',
        '%H',
        $string);
    }
    return rtrim($stdout);
  }

  protected function buildUncommittedStatus() {
    $diff_options = $this->getDiffBaseOptions();

    if ($this->repositoryHasNoCommits) {
      $diff_base = self::GIT_MAGIC_ROOT_COMMIT;
    } else {
      $diff_base = 'HEAD';
    }

    // Find uncommitted changes.
    $uncommitted_future = $this->buildLocalFuture(
      array(
        'diff %C --raw %s --',
        $diff_options,
        $diff_base,
      ));

    $untracked_future = $this->buildLocalFuture(
      array(
        'ls-files --others --exclude-standard',
      ));

    // TODO: This doesn't list unstaged adds. It's not clear how to get that
    // list other than "git status --porcelain" and then parsing it. :/

    // Unstaged changes
    $unstaged_future = $this->buildLocalFuture(
      array(
        'ls-files -m',
      ));

    $futures = array(
      $uncommitted_future,
      $untracked_future,
      $unstaged_future,
    );

    Futures($futures)->resolveAll();

    $result = new PhutilArrayWithDefaultValue();

    list($stdout) = $uncommitted_future->resolvex();
    $uncommitted_files = $this->parseGitStatus($stdout);
    foreach ($uncommitted_files as $path => $mask) {
      $result[$path] |= ($mask | self::FLAG_UNCOMMITTED);
    }

    list($stdout) = $untracked_future->resolvex();
    $stdout = rtrim($stdout, "\n");
    if (strlen($stdout)) {
      $stdout = explode("\n", $stdout);
      foreach ($stdout as $path) {
        $result[$path] |= self::FLAG_UNTRACKED;
      }
    }

    list($stdout, $stderr) = $unstaged_future->resolvex();
    $stdout = rtrim($stdout, "\n");
    if (strlen($stdout)) {
      $stdout = explode("\n", $stdout);
      foreach ($stdout as $path) {
        $result[$path] |= self::FLAG_UNSTAGED;
      }
    }

    return $result->toArray();
  }

  protected function buildCommitRangeStatus() {
    list($stdout, $stderr) = $this->execxLocal(
      'diff %C --raw %s --',
      $this->getDiffBaseOptions(),
      $this->getBaseCommit());

    return $this->parseGitStatus($stdout);
  }

  public function getGitConfig($key, $default = null) {
    list($err, $stdout) = $this->execManualLocal('config %s', $key);
    if ($err) {
      return $default;
    }
    return rtrim($stdout);
  }

  public function getAuthor() {
    list($stdout) = $this->execxLocal('var GIT_AUTHOR_IDENT');
    return preg_replace('/\s+<.*/', '', rtrim($stdout, "\n"));
  }

  public function addToCommit(array $paths) {
    $this->execxLocal(
      'add -A -- %Ls',
      $paths);
    $this->reloadWorkingCopy();
    return $this;
  }

  public function doCommit($message) {
    $tmp_file = new TempFile();
    Filesystem::writeFile($tmp_file, $message);
    $this->execxLocal(
      'commit --allow-empty-message -F %s',
      $tmp_file);

    $this->reloadWorkingCopy();

    return $this;
  }

  public function amendCommit($message) {
    $tmp_file = new TempFile();
    Filesystem::writeFile($tmp_file, $message);
    $this->execxLocal(
      'commit --amend --allow-empty -F %s',
      $tmp_file);
    $this->reloadWorkingCopy();
    return $this;
  }

  public function getPreReceiveHookStatus($old_ref, $new_ref) {
    $options = $this->getDiffBaseOptions();
    list($stdout) = $this->execxLocal(
      "diff {$options} --raw %s %s --",
      $old_ref,
      $new_ref);
    return $this->parseGitStatus($stdout, $full = true);
  }

  private function parseGitStatus($status, $full = false) {
    static $flags = array(
      'A' => self::FLAG_ADDED,
      'M' => self::FLAG_MODIFIED,
      'D' => self::FLAG_DELETED,
    );

    $status = trim($status);
    $lines = array();
    foreach (explode("\n", $status) as $line) {
      if ($line) {
        $lines[] = preg_split("/[ \t]/", $line, 6);
      }
    }

    $files = array();
    foreach ($lines as $line) {
      $mask = 0;
      $flag = $line[4];
      $file = $line[5];
      foreach ($flags as $key => $bits) {
        if ($flag == $key) {
          $mask |= $bits;
        }
      }
      if ($full) {
        $files[$file] = array(
          'mask' => $mask,
          'ref'  => rtrim($line[3], '.'),
        );
      } else {
        $files[$file] = $mask;
      }
    }

    return $files;
  }

  public function getAllFiles() {
    $future = $this->buildLocalFuture(array('ls-files -z'));
    return id(new LinesOfALargeExecFuture($future))
      ->setDelimiter("\0");
  }

  public function getChangedFiles($since_commit) {
    list($stdout) = $this->execxLocal(
      'diff --raw %s',
      $since_commit);
    return $this->parseGitStatus($stdout);
  }

  public function getBlame($path) {
    // TODO: 'git blame' supports --porcelain and we should probably use it.
    list($stdout) = $this->execxLocal(
      'blame --date=iso -w -M %s -- %s',
      $this->getBaseCommit(),
      $path);

    $blame = array();
    foreach (explode("\n", trim($stdout)) as $line) {
      if (!strlen($line)) {
        continue;
      }

      // lines predating a git repo's history are blamed to the oldest revision,
      // with the commit hash prepended by a ^. we shouldn't count these lines
      // as blaming to the oldest diff's unfortunate author
      if ($line[0] == '^') {
        continue;
      }

      $matches = null;
      $ok = preg_match(
        '/^([0-9a-f]+)[^(]+?[(](.*?) +\d\d\d\d-\d\d-\d\d/',
        $line,
        $matches);
      if (!$ok) {
        throw new Exception("Bad blame? `{$line}'");
      }
      $revision = $matches[1];
      $author = $matches[2];

      $blame[] = array($author, $revision);
    }

    return $blame;
  }

  public function getOriginalFileData($path) {
    return $this->getFileDataAtRevision($path, $this->getBaseCommit());
  }

  public function getCurrentFileData($path) {
    return $this->getFileDataAtRevision($path, 'HEAD');
  }

  private function parseGitTree($stdout) {
    $result = array();

    $stdout = trim($stdout);
    if (!strlen($stdout)) {
      return $result;
    }

    $lines = explode("\n", $stdout);
    foreach ($lines as $line) {
      $matches = array();
      $ok = preg_match(
        '/^(\d{6}) (blob|tree) ([a-z0-9]{40})[\t](.*)$/',
        $line,
        $matches);
      if (!$ok) {
        throw new Exception("Failed to parse git ls-tree output!");
      }
      $result[$matches[4]] = array(
        'mode' => $matches[1],
        'type' => $matches[2],
        'ref'  => $matches[3],
      );
    }
    return $result;
  }

  private function getFileDataAtRevision($path, $revision) {

    // NOTE: We don't want to just "git show {$revision}:{$path}" since if the
    // path was a directory at the given revision we'll get a list of its files
    // and treat it as though it as a file containing a list of other files,
    // which is silly.

    list($stdout) = $this->execxLocal(
      'ls-tree %s -- %s',
      $revision,
      $path);

    $info = $this->parseGitTree($stdout);
    if (empty($info[$path])) {
      // No such path, or the path is a directory and we executed 'ls-tree dir/'
      // and got a list of its contents back.
      return null;
    }

    if ($info[$path]['type'] != 'blob') {
      // Path is or was a directory, not a file.
      return null;
    }

    list($stdout) = $this->execxLocal(
      'cat-file blob %s',
       $info[$path]['ref']);
    return $stdout;
  }

  /**
   * Returns names of all the branches in the current repository.
   *
   * @return list<dict<string, string>> Dictionary of branch information.
   */
  public function getAllBranches() {
    list($branch_info) = $this->execxLocal(
      'branch --no-color');
    $lines = explode("\n", rtrim($branch_info));

    $result = array();
    foreach ($lines as $line) {

      if (preg_match('/^[* ]+\(no branch\)/', $line)) {
        // This is indicating that the working copy is in a detached state;
        // just ignore it.
        continue;
      }

      list($current, $name) = preg_split('/\s+/', $line, 2);
      $result[] = array(
        'current' => !empty($current),
        'name'    => $name,
      );
    }

    return $result;
  }

  public function getWorkingCopyRevision() {
    list($stdout) = $this->execxLocal('rev-parse HEAD');
    return rtrim($stdout, "\n");
  }

  public function getUnderlyingWorkingCopyRevision() {
    list($err, $stdout) = $this->execManualLocal('svn find-rev HEAD');
    if (!$err && $stdout) {
      return rtrim($stdout, "\n");
    }
    return $this->getWorkingCopyRevision();
  }

  public function isHistoryDefaultImmutable() {
    return false;
  }

  public function supportsAmend() {
    return true;
  }

  public function supportsCommitRanges() {
    return true;
  }

  public function supportsLocalCommits() {
    return true;
  }

  public function hasLocalCommit($commit) {
    try {
      if (!$this->getCanonicalRevisionName($commit)) {
        return false;
      }
    } catch (CommandException $exception) {
      return false;
    }
    return true;
  }

  public function getAllLocalChanges() {
    $diff = $this->getFullGitDiff();
    if (!strlen(trim($diff))) {
      return array();
    }
    $parser = new ArcanistDiffParser();
    return $parser->parseDiff($diff);
  }

  public function supportsLocalBranchMerge() {
    return true;
  }

  public function performLocalBranchMerge($branch, $message) {
    if (!$branch) {
      throw new ArcanistUsageException(
        "Under git, you must specify the branch you want to merge.");
    }
    $err = phutil_passthru(
      '(cd %s && git merge --no-ff -m %s %s)',
      $this->getPath(),
      $message,
      $branch);

    if ($err) {
      throw new ArcanistUsageException("Merge failed!");
    }
  }

  public function getFinalizedRevisionMessage() {
    return "You may now push this commit upstream, as appropriate (e.g. with ".
           "'git push', or 'git svn dcommit', or by printing and faxing it).";
  }

  public function getCommitMessage($commit) {
    list($message) = $this->execxLocal(
      'log -n1 --format=%C %s --',
      '%s%n%n%b',
      $commit);
    return $message;
  }

  public function loadWorkingCopyDifferentialRevisions(
    ConduitClient $conduit,
    array $query) {

    $messages = $this->getGitCommitLog();
    if (!strlen($messages)) {
      return array();
    }

    $parser = new ArcanistDiffParser();
    $messages = $parser->parseDiff($messages);

    // First, try to find revisions by explicit revision IDs in commit messages.
    $reason_map = array();
    $revision_ids = array();
    foreach ($messages as $message) {
      $object = ArcanistDifferentialCommitMessage::newFromRawCorpus(
        $message->getMetadata('message'));
      if ($object->getRevisionID()) {
        $revision_ids[] = $object->getRevisionID();
        $reason_map[$object->getRevisionID()] = $message->getCommitHash();
      }
    }

    if ($revision_ids) {
      $results = $conduit->callMethodSynchronous(
        'differential.query',
        $query + array(
          'ids' => $revision_ids,
        ));

      foreach ($results as $key => $result) {
        $hash = substr($reason_map[$result['id']], 0, 16);
        $results[$key]['why'] =
          "Commit message for '{$hash}' has explicit 'Differential Revision'.";
      }

      return $results;
    }

    // If we didn't succeed, try to find revisions by hash.
    $hashes = array();
    foreach ($this->getLocalCommitInformation() as $commit) {
      $hashes[] = array('gtcm', $commit['commit']);
      $hashes[] = array('gttr', $commit['tree']);
    }

    $results = $conduit->callMethodSynchronous(
      'differential.query',
      $query + array(
        'commitHashes' => $hashes,
      ));

    foreach ($results as $key => $result) {
      $results[$key]['why'] =
        "A git commit or tree hash in the commit range is already attached ".
        "to the Differential revision.";
    }

    return $results;
  }

  public function updateWorkingCopy() {
    $this->execxLocal('pull');
    $this->reloadWorkingCopy();
  }

  public function getCommitSummary($commit) {
    if ($commit == self::GIT_MAGIC_ROOT_COMMIT) {
      return '(The Empty Tree)';
    }

    list($summary) = $this->execxLocal(
      'log -n 1 --format=%C %s',
      '%s',
      $commit);

    return trim($summary);
  }

  public function resolveBaseCommitRule($rule, $source) {
    list($type, $name) = explode(':', $rule, 2);

    switch ($type) {
      case 'git':
        $matches = null;
        if (preg_match('/^merge-base\((.+)\)$/', $name, $matches)) {
          list($err, $merge_base) = $this->execManualLocal(
            'merge-base %s HEAD',
            $matches[1]);
          if (!$err) {
            $this->setBaseCommitExplanation(
              "it is the merge-base of '{$matches[1]}' and HEAD, as ".
              "specified by '{$rule}' in your {$source} 'base' ".
              "configuration.");
            return trim($merge_base);
          }
        } else if (preg_match('/^branch-unique\((.+)\)$/', $name, $matches)) {
          list($err, $merge_base) = $this->execManualLocal(
            'merge-base %s HEAD',
            $matches[1]);
          if ($err) {
            return null;
          }
          $merge_base = trim($merge_base);

          list($commits) = $this->execxLocal(
            'log --format=%C %s..HEAD --',
            '%H',
            $merge_base);
          $commits = array_filter(explode("\n", $commits));

          if (!$commits) {
            return null;
          }

          $commits[] = $merge_base;

          $head_branch_count = null;
          foreach ($commits as $commit) {
            list($branches) = $this->execxLocal(
              'branch --contains %s',
              $commit);
            $branches = array_filter(explode("\n", $branches));
            if ($head_branch_count === null) {
              // If this is the first commit, it's HEAD. Count how many
              // branches it is on; we want to include commits on the same
              // number of branches. This covers a case where this branch
              // has sub-branches and we're running "arc diff" here again
              // for whatever reason.
              $head_branch_count = count($branches);
            } else if (count($branches) > $head_branch_count) {
              foreach ($branches as $key => $branch) {
                $branches[$key] = trim($branch, ' *');
              }
              $branches = implode(', ', $branches);
              $this->setBaseCommitExplanation(
                "it is the first commit between '{$merge_base}' (the ".
                "merge-base of '{$matches[1]}' and HEAD) which is also ".
                "contained by another branch ({$branches}).");
              return $commit;
            }
          }
        } else {
          list($err) = $this->execManualLocal(
            'cat-file -t %s',
            $name);
          if (!$err) {
            $this->setBaseCommitExplanation(
              "it is specified by '{$rule}' in your {$source} 'base' ".
              "configuration.");
            return $name;
          }
        }
        break;
      case 'arc':
        switch ($name) {
          case 'empty':
            $this->setBaseCommitExplanation(
              "you specified '{$rule}' in your {$source} 'base' ".
              "configuration.");
            return self::GIT_MAGIC_ROOT_COMMIT;
          case 'amended':
            $text = $this->getCommitMessage('HEAD');
            $message = ArcanistDifferentialCommitMessage::newFromRawCorpus(
              $text);
            if ($message->getRevisionID()) {
              $this->setBaseCommitExplanation(
                "HEAD has been amended with 'Differential Revision:', ".
                "as specified by '{$rule}' in your {$source} 'base' ".
                "configuration.");
              return 'HEAD^';
            }
            break;
          case 'upstream':
            list($err, $upstream) = $this->execManualLocal(
              "rev-parse --abbrev-ref --symbolic-full-name '@{upstream}'");
            if (!$err) {
              $upstream = rtrim($upstream);
              list($upstream_merge_base) = $this->execxLocal(
                'merge-base %s HEAD',
                $upstream);
              $upstream_merge_base = rtrim($upstream_merge_base);
              $this->setBaseCommitExplanation(
                "it is the merge-base of the upstream of the current branch ".
                "and HEAD, and matched the rule '{$rule}' in your {$source} ".
                "'base' configuration.");
              return $upstream_merge_base;
            }
            break;
          case 'this':
            $this->setBaseCommitExplanation(
              "you specified '{$rule}' in your {$source} 'base' ".
              "configuration.");
            return 'HEAD^';
        }
      default:
        return null;
    }

    return null;
  }

}
