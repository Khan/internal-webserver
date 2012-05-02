<?php

/*
 * Copyright 2012 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * Interfaces with Subversion working copies.
 *
 * @group workingcopy
 */
final class ArcanistSubversionAPI extends ArcanistRepositoryAPI {

  protected $svnStatus;
  protected $svnBaseRevisions;
  protected $svnInfo = array();

  protected $svnInfoRaw = array();
  protected $svnDiffRaw = array();

  private $svnBaseRevisionNumber;

  public function getSourceControlSystemName() {
    return 'svn';
  }

  protected function buildLocalFuture(array $argv) {

    $argv[0] = 'svn '.$argv[0];

    $future = newv('ExecFuture', $argv);
    $future->setCWD($this->getPath());
    return $future;
  }


  public function hasMergeConflicts() {
    foreach ($this->getSVNStatus() as $path => $mask) {
      if ($mask & self::FLAG_CONFLICT) {
        return true;
      }
    }
    return false;
  }

  public function getWorkingCopyStatus() {
    return $this->getSVNStatus();
  }

  public function getSVNBaseRevisions() {
    if ($this->svnBaseRevisions === null) {
      $this->getSVNStatus();
    }
    return $this->svnBaseRevisions;
  }

  public function getSVNStatus($with_externals = false) {
    if ($this->svnStatus === null) {
      list($status) = execx('(cd %s && svn --xml status)', $this->getPath());
      $xml = new SimpleXMLElement($status);

      if (count($xml->target) != 1) {
        throw new Exception("Expected exactly one XML status target.");
      }

      $externals = array();
      $files = array();

      $target = $xml->target[0];
      $this->svnBaseRevisions = array();
      foreach ($target->entry as $entry) {
        $path = (string)$entry['path'];
        $mask = 0;

        $props = (string)($entry->{'wc-status'}[0]['props']);
        $item  = (string)($entry->{'wc-status'}[0]['item']);

        $base = (string)($entry->{'wc-status'}[0]['revision']);
        $this->svnBaseRevisions[$path] = $base;

        switch ($props) {
          case 'none':
          case 'normal':
            break;
          case 'modified':
            $mask |= self::FLAG_MODIFIED;
            break;
          default:
            throw new Exception("Unrecognized property status '{$props}'.");
        }

        switch ($item) {
          case 'normal':
            break;
          case 'external':
            $mask |= self::FLAG_EXTERNALS;
            $externals[] = $path;
            break;
          case 'unversioned':
            $mask |= self::FLAG_UNTRACKED;
            break;
          case 'obstructed':
            $mask |= self::FLAG_OBSTRUCTED;
            break;
          case 'missing':
            $mask |= self::FLAG_MISSING;
            break;
          case 'added':
            $mask |= self::FLAG_ADDED;
            break;
          case 'replaced':
            // This is the result of "svn rm"-ing a file, putting another one
            // in place of it, and then "svn add"-ing the new file. Just treat
            // this as equivalent to "modified".
            $mask |= self::FLAG_MODIFIED;
            break;
          case 'modified':
            $mask |= self::FLAG_MODIFIED;
            break;
          case 'deleted':
            $mask |= self::FLAG_DELETED;
            break;
          case 'conflicted':
            $mask |= self::FLAG_CONFLICT;
            break;
          case 'incomplete':
            $mask |= self::FLAG_INCOMPLETE;
            break;
          default:
            throw new Exception("Unrecognized item status '{$item}'.");
        }

        // This is new in or around Subversion 1.6.
        $tree_conflicts = (string)($entry->{'wc-status'}[0]['tree-conflicted']);
        if ($tree_conflicts) {
          $mask |= self::FLAG_CONFLICT;
        }

        $files[$path] = $mask;
      }

      foreach ($files as $path => $mask) {
        foreach ($externals as $external) {
          if (!strncmp($path, $external, strlen($external))) {
            $files[$path] |= self::FLAG_EXTERNALS;
          }
        }
      }

      $this->svnStatus = $files;
    }

    $status = $this->svnStatus;
    if (!$with_externals) {
      foreach ($status as $path => $mask) {
        if ($mask & ArcanistRepositoryAPI::FLAG_EXTERNALS) {
          unset($status[$path]);
        }
      }
    }

    return $status;
  }

  public function getSVNProperty($path, $property) {
    list($stdout) = execx(
      'svn propget %s %s@',
      $property,
      $this->getPath($path));
    return trim($stdout);
  }

  public function getSourceControlPath() {
    return idx($this->getSVNInfo('/'), 'URL');
  }

  public function getSourceControlBaseRevision() {
    $info = $this->getSVNInfo('/');
    return $info['URL'].'@'.$this->getSVNBaseRevisionNumber();
  }

  public function getCanonicalRevisionName($string) {
    throw new ArcanistCapabilityNotSupportedException($this);
  }

  public function getSVNBaseRevisionNumber() {
    if ($this->svnBaseRevisionNumber) {
      return $this->svnBaseRevisionNumber;
    }
    $info = $this->getSVNInfo('/');
    return $info['Revision'];
  }

  public function overrideSVNBaseRevisionNumber($effective_base_revision) {
    $this->svnBaseRevisionNumber = $effective_base_revision;
    return $this;
  }

  public function getBranchName() {
    return 'svn';
  }

  public function buildInfoFuture($path) {
    if ($path == '/') {
      // When the root of a working copy is referenced by a symlink and you
      // execute 'svn info' on that symlink, svn fails. This is a longstanding
      // bug in svn:
      //
      // See http://subversion.tigris.org/issues/show_bug.cgi?id=2305
      //
      // To reproduce, do:
      //
      //  $ ln -s working_copy working_link
      //  $ svn info working_copy # ok
      //  $ svn info working_link # fails
      //
      // Work around this by cd-ing into the directory before executing
      // 'svn info'.
      return new ExecFuture(
        '(cd %s && svn info .)',
        $this->getPath());
    } else {
      // Note: here and elsewhere we need to append "@" to the path because if
      // a file has a literal "@" in it, everything after that will be
      // interpreted as a revision. By appending "@" with no argument, SVN
      // parses it properly.
      return new ExecFuture(
        'svn info %s@',
        $this->getPath($path));
    }
  }

  public function buildDiffFuture($path) {
    // The "--depth empty" flag prevents us from picking up changes in
    // children when we run 'diff' against a directory. Specifically, when a
    // user has added or modified some directory "example/", we want to return
    // ONLY changes to that directory when given it as a path. If we run
    // without "--depth empty", svn will give us changes to the directory
    // itself (such as property changes) and also give us changes to any
    // files within the directory (basically, implicit recursion). We don't
    // want that, so prevent recursive diffing.
    return new ExecFuture(
      '(cd %s; svn diff --depth empty --diff-cmd diff -x -U%d %s)',
      $this->getPath(),
      $this->getDiffLinesOfContext(),
      $path);
  }

  public function primeSVNInfoResult($path, $result) {
    $this->svnInfoRaw[$path] = $result;
    return $this;
  }

  public function primeSVNDiffResult($path, $result) {
    $this->svnDiffRaw[$path] = $result;
    return $this;
  }

  public function getSVNInfo($path) {

    if (empty($this->svnInfo[$path])) {

      if (empty($this->svnInfoRaw[$path])) {
        $this->svnInfoRaw[$path] = $this->buildInfoFuture($path)->resolve();
      }

      list($err, $stdout) = $this->svnInfoRaw[$path];
      if ($err) {
        throw new Exception(
          "Error #{$err} executing svn info against '{$path}'.");
      }

      $patterns = array(
        '/^(URL): (\S+)$/m',
        '/^(Revision): (\d+)$/m',
        '/^(Last Changed Author): (\S+)$/m',
        '/^(Last Changed Rev): (\d+)$/m',
        '/^(Last Changed Date): (.+) \(.+\)$/m',
        '/^(Copied From URL): (\S+)$/m',
        '/^(Copied From Rev): (\d+)$/m',
        '/^(Repository UUID): (\S+)$/m',
        '/^(Node Kind): (\S+)$/m',
      );

      $result = array();
      foreach ($patterns as $pattern) {
        $matches = null;
        if (preg_match($pattern, $stdout, $matches)) {
          $result[$matches[1]] = $matches[2];
        }
      }

      if (isset($result['Last Changed Date'])) {
        $result['Last Changed Date'] = strtotime($result['Last Changed Date']);
      }

      if (empty($result)) {
        throw new Exception('Unable to parse SVN info.');
      }

      $this->svnInfo[$path] = $result;
    }

    return $this->svnInfo[$path];
  }


  public function getRawDiffText($path) {
    $status = $this->getSVNStatus();
    if (!isset($status[$path])) {
      return null;
    }

    $status = $status[$path];

    // Build meaningful diff text for "svn copy" operations.
    if ($status & ArcanistRepositoryAPI::FLAG_ADDED) {
      $info = $this->getSVNInfo($path);
      if (!empty($info['Copied From URL'])) {
        return $this->buildSyntheticAdditionDiff(
          $path,
          $info['Copied From URL'],
          $info['Copied From Rev']);
      }
    }

    // If we run "diff" on a binary file which doesn't have the "svn:mime-type"
    // of "application/octet-stream", `diff' will explode in a rain of
    // unhelpful hellfire as it tries to build a textual diff of the two
    // files. We just fix this inline since it's pretty unambiguous.
    // TODO: Move this to configuration?
    $matches = null;
    if (preg_match('/\.(gif|png|jpe?g|swf|pdf|ico)$/i', $path, $matches)) {
      $mime = $this->getSVNProperty($path, 'svn:mime-type');
      if ($mime != 'application/octet-stream') {
        execx(
          'svn propset svn:mime-type application/octet-stream %s',
          $this->getPath($path));
      }
    }

    if (empty($this->svnDiffRaw[$path])) {
      $this->svnDiffRaw[$path] = $this->buildDiffFuture($path)->resolve();
    }

    list($err, $stdout, $stderr) = $this->svnDiffRaw[$path];

    // Note: GNU Diff returns 2 when SVN hands it binary files to diff and they
    // differ. This is not an error; it is documented behavior. But SVN isn't
    // happy about it. SVN will exit with code 1 and return the string below.
    if ($err != 0 && $stderr !== "svn: 'diff' returned 2\n") {
      throw new Exception(
        "svn diff returned unexpected error code: $err\n".
        "stdout: $stdout\n".
        "stderr: $stderr");
    }

    if ($err == 0 && empty($stdout)) {
      // If there are no changes, 'diff' exits with no output, but that means
      // we can not distinguish between empty and unmodified files. Build a
      // synthetic "diff" without any changes in it.
      return $this->buildSyntheticUnchangedDiff($path);
    }

    return $stdout;
  }

  protected function buildSyntheticAdditionDiff($path, $source, $rev) {
    $type = $this->getSVNProperty($path, 'svn:mime-type');
    if ($type == 'application/octet-stream') {
      return <<<EODIFF
Index: {$path}
===================================================================
Cannot display: file marked as a binary type.
svn:mime-type = application/octet-stream

EODIFF;
    }

    if (is_dir($this->getPath($path))) {
      return null;
    }

    $data = Filesystem::readFile($this->getPath($path));
    list($orig) = execx('svn cat %s@%s', $source, $rev);

    $src = new TempFile();
    $dst = new TempFile();
    Filesystem::writeFile($src, $orig);
    Filesystem::writeFile($dst, $data);

    list($err, $diff) = exec_manual(
      'diff -L a/%s -L b/%s -U%d %s %s',
      str_replace($this->getSourceControlPath().'/', '', $source),
      $path,
      $this->getDiffLinesOfContext(),
      $src,
      $dst);

    if ($err == 1) { // 1 means there are differences.
      return <<<EODIFF
Index: {$path}
===================================================================
{$diff}

EODIFF;
    } else {
      return $this->buildSyntheticUnchangedDiff($path);
    }
  }

  protected function buildSyntheticUnchangedDiff($path) {
    $full_path = $this->getPath($path);
    if (is_dir($full_path)) {
      return null;
    }

    if (!file_exists($full_path)) {
      return null;
    }

    $data = Filesystem::readFile($full_path);
    $lines = explode("\n", $data);
    $len = count($lines);
    foreach ($lines as $key => $line) {
      $lines[$key] = ' '.$line;
    }
    $lines = implode("\n", $lines);
    return <<<EODIFF
Index: {$path}
===================================================================
--- {$path} (synthetic)
+++ {$path} (synthetic)
@@ -1,{$len} +1,{$len} @@
{$lines}

EODIFF;
  }

  public function getBlame($path) {
    $blame = array();

    list($stdout) = execx(
      '(cd %s && svn blame %s)',
      $this->getPath(),
      $path);

    $stdout = trim($stdout);
    if (!strlen($stdout)) {
      // Empty file.
      return $blame;
    }

    foreach (explode("\n", $stdout) as $line) {
      $m = array();
      if (!preg_match('/^\s*(\d+)\s+(\S+)/', $line, $m)) {
        throw new Exception("Bad blame? `{$line}'");
      }
      $revision = $m[1];
      $author = $m[2];
      $blame[] = array($author, $revision);
    }

    return $blame;
  }

  public function getOriginalFileData($path) {
    // SVN issues warnings for nonexistent paths, directories, etc., but still
    // returns no error code. However, for new paths in the working copy it
    // fails. Assume that failure means the original file does not exist.
    list($err, $stdout) = exec_manual(
      '(cd %s && svn cat %s@)',
      $this->getPath(),
      $path);
    if ($err) {
      return null;
    }
    return $stdout;
  }

  public function getCurrentFileData($path) {
    $full_path = $this->getPath($path);
    if (Filesystem::pathExists($full_path)) {
      return Filesystem::readFile($full_path);
    }
    return null;
  }

  public function getRepositorySVNUUID() {
    $info = $this->getSVNInfo('/');
    return $info['Repository UUID'];
  }

  public function getLocalCommitInformation() {
    return null;
  }

  public function supportsRelativeLocalCommits() {
    return false;
  }

  public function hasLocalCommit($commit) {
    return false;
  }

  public function getWorkingCopyRevision() {
    return $this->getSourceControlBaseRevision();
  }

  public function supportsLocalBranchMerge() {
    return false;
  }

  public function getFinalizedRevisionMessage() {
    // In other VCSes we give push instructions here, but it never makes sense
    // in SVN.
    return "Done.";
  }

  public function loadWorkingCopyDifferentialRevisions(
    ConduitClient $conduit,
    array $query) {

    // We don't have much to go on in SVN, look for revisions that came from
    // this directory and belong to the same project.

    $project = $this->getWorkingCopyIdentity()->getProjectID();
    $results = $conduit->callMethodSynchronous(
      'differential.query',
      $query + array(
        'arcanistProjects' => array($project),
      ));

    foreach ($results as $key => $result) {
      if ($result['sourcePath'] != $this->getPath()) {
        unset($results[$key]);
      }
    }

    return $results;
  }

  public function updateWorkingCopy() {
    $this->execxLocal('up');
  }

}
