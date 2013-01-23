<?php

/**
 * Parses diffs from a working copy.
 *
 * @group diff
 */
final class ArcanistDiffParser {

  protected $repositoryAPI;
  protected $text;
  protected $line;
  protected $lineSaved;
  protected $isGit;
  protected $isMercurial;
  protected $detectBinaryFiles = false;
  protected $tryEncoding;
  protected $rawDiff;
  protected $writeDiffOnFailure;

  protected $changes = array();
  private $forcePath;

  public function setRepositoryAPI(ArcanistRepositoryAPI $repository_api) {
    $this->repositoryAPI = $repository_api;
    return $this;
  }

  public function setDetectBinaryFiles($detect) {
    $this->detectBinaryFiles = $detect;
    return $this;
  }

  public function setTryEncoding($encoding) {
    $this->tryEncoding = $encoding;
    return $this;
  }

  public function forcePath($path) {
    $this->forcePath = $path;
    return $this;
  }

  public function setChanges(array $changes) {
    assert_instances_of($changes, 'ArcanistDiffChange');
    $this->changes = mpull($changes, null, 'getCurrentPath');
    return $this;
  }

  public function parseSubversionDiff(ArcanistSubversionAPI $api, $paths) {
    $this->setRepositoryAPI($api);

    $diffs = array();

    foreach ($paths as $path => $status) {
      if ($status & ArcanistRepositoryAPI::FLAG_UNTRACKED ||
          $status & ArcanistRepositoryAPI::FLAG_CONFLICT ||
          $status & ArcanistRepositoryAPI::FLAG_MISSING) {
        unset($paths[$path]);
      }
    }

    $root = null;
    $from = array();
    foreach ($paths as $path => $status) {
      $change = $this->buildChange($path);

      if ($status & ArcanistRepositoryAPI::FLAG_ADDED) {
        $change->setType(ArcanistDiffChangeType::TYPE_ADD);
      } else if ($status & ArcanistRepositoryAPI::FLAG_DELETED) {
        $change->setType(ArcanistDiffChangeType::TYPE_DELETE);
      } else {
        $change->setType(ArcanistDiffChangeType::TYPE_CHANGE);
      }

      $is_dir = is_dir($api->getPath($path));
      if ($is_dir) {
        $change->setFileType(ArcanistDiffChangeType::FILE_DIRECTORY);
        // We have to go hit the diff even for directories because they may
        // have property changes or moves, etc.
      }
      $is_link = is_link($api->getPath($path));
      if ($is_link) {
        $change->setFileType(ArcanistDiffChangeType::FILE_SYMLINK);
      }

      $diff = $api->getRawDiffText($path);
      if ($diff) {
        $this->parseDiff($diff);
      }

      $info = $api->getSVNInfo($path);
      if (idx($info, 'Copied From URL')) {
        if (!$root) {
          $rinfo = $api->getSVNInfo('.');
          $root = $rinfo['URL'].'/';
        }
        $cpath = $info['Copied From URL'];
        $root_len = strlen($root);
        if (!strncmp($cpath, $root, $root_len)) {
          $cpath = substr($cpath, $root_len);
          // The user can "svn cp /path/to/file@12345 x", which pulls a file out
          // of version history at a specific revision. If we just use the path,
          // we'll collide with possible changes to that path in the working
          // copy below. In particular, "svn cp"-ing a path which no longer
          // exists somewhere in the working copy and then adding that path
          // gets us to the "origin change type" branches below with a
          // TYPE_ADD state on the path. To avoid this, append the origin
          // revision to the path so we'll necessarily generate a new change.
          // TODO: In theory, you could have an '@' in your path and this could
          // cause a collision, e.g. two files named 'f' and 'f@12345'. This is
          // at least somewhat the user's fault, though.
          if ($info['Copied From Rev']) {
            if ($info['Copied From Rev'] != $info['Revision']) {
              $cpath .= '@'.$info['Copied From Rev'];
            }
          }
          $change->setOldPath($cpath);
          $from[$path] = $cpath;
        }
      }

      $type = $change->getType();
      if (($type === ArcanistDiffChangeType::TYPE_MOVE_AWAY ||
           $type === ArcanistDiffChangeType::TYPE_DELETE) &&
          idx($info, 'Node Kind') === 'directory') {
        $change->setFileType(ArcanistDiffChangeType::FILE_DIRECTORY);
      }
    }

    foreach ($paths as $path => $status) {
      $change = $this->buildChange($path);
      if (empty($from[$path])) {
        continue;
      }

      if (empty($this->changes[$from[$path]])) {
        if ($change->getType() == ArcanistDiffChangeType::TYPE_COPY_HERE) {
          // If the origin path wasn't changed (or isn't included in this diff)
          // and we only copied it, don't generate a changeset for it. This
          // keeps us out of trouble when we go to 'arc commit' and need to
          // figure out which files should be included in the commit list.
          continue;
        }
      }

      $origin = $this->buildChange($from[$path]);
      $origin->addAwayPath($change->getCurrentPath());

      $type = $origin->getType();
      switch ($type) {
        case ArcanistDiffChangeType::TYPE_MULTICOPY:
        case ArcanistDiffChangeType::TYPE_COPY_AWAY:
        // "Add" is possible if you do some bizarre tricks with svn:ignore and
        // "svn copy"'ing URLs straight from the repository; you can end up with
        // a file that is a copy of itself. See T271.
        case ArcanistDiffChangeType::TYPE_ADD:
          break;
        case ArcanistDiffChangeType::TYPE_DELETE:
          $origin->setType(ArcanistDiffChangeType::TYPE_MOVE_AWAY);
          break;
        case ArcanistDiffChangeType::TYPE_MOVE_AWAY:
          $origin->setType(ArcanistDiffChangeType::TYPE_MULTICOPY);
          break;
        case ArcanistDiffChangeType::TYPE_CHANGE:
          $origin->setType(ArcanistDiffChangeType::TYPE_COPY_AWAY);
          break;
        default:
          throw new Exception("Bad origin state {$type}.");
      }

      $type = $origin->getType();
      switch ($type) {
        case ArcanistDiffChangeType::TYPE_MULTICOPY:
        case ArcanistDiffChangeType::TYPE_MOVE_AWAY:
          $change->setType(ArcanistDiffChangeType::TYPE_MOVE_HERE);
          break;
        case ArcanistDiffChangeType::TYPE_ADD:
        case ArcanistDiffChangeType::TYPE_COPY_AWAY:
          $change->setType(ArcanistDiffChangeType::TYPE_COPY_HERE);
          break;
        default:
          throw new Exception("Bad origin state {$type}.");
      }
    }

    return $this->changes;
  }

  public function parseDiff($diff) {
    if (!strlen(trim($diff))) {
      throw new Exception("Can't parse an empty diff!");
    }

    $this->didStartParse($diff);

    do {
      $patterns = array(
        // This is a normal SVN text change, probably from "svn diff".
        '(?P<type>Index): (?P<cur>.+)',
        // This is an SVN property change, probably from "svn diff".
        '(?P<type>Property changes on): (?P<cur>.+)',
        // This is a git commit message, probably from "git show".
        '(?P<type>commit) (?P<hash>[a-f0-9]+)(?: \(.*\))?',
        // This is a git diff, probably from "git show" or "git diff".
        // Note that the filenames may appear quoted.
        '(?P<type>diff --git) (?P<oldnew>.*)',
        // This is a unified diff, probably from "diff -u" or synthetic diffing.
        '(?P<type>---) (?P<old>.+)\s+\d{4}-\d{2}-\d{2}.*',
        '(?P<binary>Binary) files '.
          '(?P<old>.+)\s+\d{4}-\d{2}-\d{2} and '.
          '(?P<new>.+)\s+\d{4}-\d{2}-\d{2} differ.*',
        // This is a normal Mercurial text change, probably from "hg diff". It
        // may have two "-r" blocks if it came from "hg diff -r x:y".
        '(?P<type>diff -r) (?P<hgrev>[a-f0-9]+) (?:-r [a-f0-9]+ )?(?P<cur>.+)',
      );

      $line = $this->getLineTrimmed();
      $match = null;
      $ok = $this->tryMatchHeader($patterns, $line, $match);

      $failed_parse = false;
      if (!$ok && $this->isFirstNonEmptyLine()) {
        // 'hg export' command creates so called "extended diff" that
        // contains some meta information and comment at the beginning
        // (isFirstNonEmptyLine() to check for beginning). Actual mercurial
        // code detects where comment ends and unified diff starts by
        // searching for "diff -r" or "diff --git" in the text.
        $this->saveLine();
        $line = $this->nextLineThatLooksLikeDiffStart();
        if (!$this->tryMatchHeader($patterns, $line, $match)) {
          // Restore line before guessing to display correct error.
          $this->restoreLine();
          $failed_parse = true;
        }
      } else if (!$ok) {
        $failed_parse = true;
      }

      if ($failed_parse) {
        $this->didFailParse(
          "Expected a hunk header, like 'Index: /path/to/file.ext' (svn), ".
          "'Property changes on: /path/to/file.ext' (svn properties), ".
          "'commit 59bcc3ad6775562f845953cf01624225' (git show), ".
          "'diff --git' (git diff), '--- filename' (unified diff), or " .
          "'diff -r' (hg diff or patch).");
      }

      if (isset($match['type'])) {
        if ($match['type'] == 'diff --git') {
          list($old, $new) = self::splitGitDiffPaths($match['oldnew']);
          $match['old'] = $old;
          $match['cur'] = $new;
        }
      }

      $change = $this->buildChange(idx($match, 'cur'));

      if (isset($match['old'])) {
        $change->setOldPath($match['old']);
      }

      if (isset($match['hash'])) {
        $change->setCommitHash($match['hash']);
      }

      if (isset($match['binary'])) {
        $change->setFileType(ArcanistDiffChangeType::FILE_BINARY);
        $line = $this->nextNonemptyLine();
        continue;
      }

      $line = $this->nextLine();

      switch ($match['type']) {
        case 'Index':
          $this->parseIndexHunk($change);
          break;
        case 'Property changes on':
          $this->parsePropertyHunk($change);
          break;
        case 'diff --git':
          $this->setIsGit(true);
          $this->parseIndexHunk($change);
          break;
        case 'commit':
          $this->setIsGit(true);
          $this->parseCommitMessage($change);
          break;
        case '---':
          $ok = preg_match(
            '@^(?:\+\+\+) (.*)\s+\d{4}-\d{2}-\d{2}.*$@',
            $line,
            $match);
          if (!$ok) {
            $this->didFailParse("Expected '+++ filename' in unified diff.");
          }
          $change->setCurrentPath($match[1]);
          $line = $this->nextLine();
          $this->parseChangeset($change);
          break;
        case 'diff -r':
          $this->setIsMercurial(true);
          $this->parseIndexHunk($change);
          break;
        default:
          $this->didFailParse("Unknown diff type.");
          break;
      }
    } while ($this->getLine() !== null);

    $this->didFinishParse();

    $this->loadSyntheticData();

    return $this->changes;
  }

  protected function tryMatchHeader($patterns, $line, &$match) {
    foreach ($patterns as $pattern) {
      if (preg_match('@^'.$pattern.'$@', $line, $match)) {
        return true;
      }
    }
    return false;
  }

  protected function parseCommitMessage(ArcanistDiffChange $change) {
    $change->setType(ArcanistDiffChangeType::TYPE_MESSAGE);

    $message = array();

    $line = $this->getLine();
    if (preg_match('/^Merge: /', $line)) {
      $this->nextLine();
    }

    $line = $this->getLine();
    if (!preg_match('/^Author: /', $line)) {
      $this->didFailParse("Expected 'Author:'.");
    }

    $line = $this->nextLine();
    if (!preg_match('/^Date: /', $line)) {
      $this->didFailParse("Expected 'Date:'.");
    }

    while (($line = $this->nextLineTrimmed()) !== null) {
      if (strlen($line) && $line[0] != ' ') {
        break;
      }

      // Strip leading spaces from Git commit messages. Note that empty lines
      // are represented as just "\n"; don't touch those.
      $message[] = preg_replace('/^    /', '', $this->getLine());
    }

    $message = rtrim(implode('', $message), "\r\n");
    $change->setMetadata('message', $message);
  }

  /**
   * Parse an SVN property change hunk. These hunks are ambiguous so just sort
   * of try to get it mostly right. It's entirely possible to foil this parser
   * (or any other parser) with a carefully constructed property change.
   */
  protected function parsePropertyHunk(ArcanistDiffChange $change) {
    $line = $this->getLine();
    if (!preg_match('/^_+$/', $line)) {
      $this->didFailParse("Expected '______________________'.");
    }

    $line = $this->nextLine();
    while ($line !== null) {
      $done = preg_match('/^(Index|Property changes on):/', $line);
      if ($done) {
        break;
      }

      $matches = null;
      $ok = preg_match('/^(Modified|Added|Deleted): (.*)$/', $line, $matches);
      if (!$ok) {
        $this->didFailParse("Expected 'Added', 'Deleted', or 'Modified'.");
      }

      $op = $matches[1];
      $prop = $matches[2];

      list($old, $new) = $this->parseSVNPropertyChange($op, $prop);

      if ($old !== null) {
        $change->setOldProperty($prop, $old);
      }

      if ($new !== null) {
        $change->setNewProperty($prop, $new);
      }

      $line = $this->getLine();
    }
  }

  private function parseSVNPropertyChange($op, $prop) {

    $old = array();
    $new = array();

    $target = null;

    $line = $this->nextLine();
    $prop_index = 2;
    while ($line !== null) {
      $done = preg_match(
        '/^(Modified|Added|Deleted|Index|Property changes on):/',
        $line);
      if ($done) {
        break;
      }
      $trimline = ltrim($line);
      if ($trimline && $trimline[0] == '#') {
        // in svn1.7, a line like ## -0,0 +1 ## is put between the Added: line
        // and the line with the property change. If we have such a line, we'll
        // just ignore it (:
        $line = $this->nextLine();
        $prop_index = 1;
        $trimline = ltrim($line);
      }
      if ($trimline && $trimline[0] == '+') {
        if ($op == 'Deleted') {
          $this->didFailParse('Unexpected "+" section in property deletion.');
        }
        $target = 'new';
        $line = substr($trimline, $prop_index);
      } else if ($trimline && $trimline[0] == '-') {
        if ($op == 'Added') {
          $this->didFailParse('Unexpected "-" section in property addition.');
        }
        $target = 'old';
        $line = substr($trimline, $prop_index);
      } else if (!strncmp($trimline, 'Merged', 6)) {
        if ($op == 'Added') {
          $target = 'new';
        } else {
          // These can appear on merges. No idea how to interpret this (unclear
          // what the old / new values are) and it's of dubious usefulness so
          // just throw it away until someone complains.
          $target = null;
        }
        $line = $trimline;
      }

      if ($target == 'new') {
        $new[] = $line;
      } else if ($target == 'old') {
        $old[] = $line;
      }

      $line = $this->nextLine();
    }

    $old = rtrim(implode('', $old));
    $new = rtrim(implode('', $new));

    if (!strlen($old)) {
      $old = null;
    }

    if (!strlen($new)) {
      $new = null;
    }

    return array($old, $new);
  }

  protected function setIsGit($git) {
    if ($this->isGit !== null && $this->isGit != $git) {
      throw new Exception("Git status has changed!");
    }
    $this->isGit = $git;
    return $this;
  }

  protected function getIsGit() {
    return $this->isGit;
  }

  public function setIsMercurial($is_mercurial) {
    $this->isMercurial = $is_mercurial;
    return $this;
  }

  public function getIsMercurial() {
    return $this->isMercurial;
  }

  protected function parseIndexHunk(ArcanistDiffChange $change) {
    $is_git = $this->getIsGit();
    $is_mercurial = $this->getIsMercurial();
    $is_svn = (!$is_git && !$is_mercurial);

    $move_source = null;

    $line = $this->getLine();
    if ($is_git) {
      do {

        $patterns = array(
          '(?P<new>new) file mode (?P<newmode>\d+)',
          '(?P<deleted>deleted) file mode (?P<oldmode>\d+)',
          // These occur when someone uses `chmod` on a file.
          'old mode (?P<oldmode>\d+)',
          'new mode (?P<newmode>\d+)',
          // These occur when you `mv` a file and git figures it out.
          'similarity index ',
          'rename from (?P<old>.*)',
          '(?P<move>rename) to (?P<cur>.*)',
          'copy from (?P<old>.*)',
          '(?P<copy>copy) to (?P<cur>.*)'
        );

        $ok = false;
        $match = null;
        foreach ($patterns as $pattern) {
          $ok = preg_match('@^'.$pattern.'@', $line, $match);
          if ($ok) {
            break;
          }
        }

        if (!$ok) {
          if ($line === null ||
              preg_match('/^(diff --git|commit) /', $line)) {
            // In this case, there are ONLY file mode changes, or this is a
            // pure move. If it's a move, flag these changesets so we can build
            // synthetic changes later, enabling us to show file contents in
            // Differential -- git only gives us a block like this:
            //
            //   diff --git a/README b/READYOU
            //   similarity index 100%
            //   rename from README
            //   rename to READYOU
            //
            // ...i.e., there is no associated diff.

            // This allows us to distinguish between property changes only
            // and actual moves. For property changes only, we can't currently
            // build a synthetic diff correctly, so just skip it.
            // TODO: Build synthetic diffs for property changes, too.
            if ($change->getType() != ArcanistDiffChangeType::TYPE_CHANGE) {
              $change->setNeedsSyntheticGitHunks(true);
              if ($move_source) {
                $move_source->setNeedsSyntheticGitHunks(true);
              }
            }
            return;
          }
          break;
        }

        if (!empty($match['oldmode'])) {
          $change->setOldProperty('unix:filemode', $match['oldmode']);
        }
        if (!empty($match['newmode'])) {
          $change->setNewProperty('unix:filemode', $match['newmode']);
        }

        if (!empty($match['deleted'])) {
          $change->setType(ArcanistDiffChangeType::TYPE_DELETE);
        }

        if (!empty($match['new'])) {
          // If you replace a symlink with a normal file, git renders the change
          // as a "delete" of the symlink plus an "add" of the new file. We
          // prefer to represent this as a change.
          if ($change->getType() == ArcanistDiffChangeType::TYPE_DELETE) {
            $change->setType(ArcanistDiffChangeType::TYPE_CHANGE);
          } else {
            $change->setType(ArcanistDiffChangeType::TYPE_ADD);
          }
        }

        if (!empty($match['old'])) {
          $match['old'] = self::unescapeFilename($match['old']);
          $change->setOldPath($match['old']);
        }

        if (!empty($match['cur'])) {
          $match['cur'] = self::unescapeFilename($match['cur']);
          $change->setCurrentPath($match['cur']);
        }

        if (!empty($match['copy'])) {
          $change->setType(ArcanistDiffChangeType::TYPE_COPY_HERE);
          $old = $this->buildChange($change->getOldPath());
          $type = $old->getType();

          if ($type == ArcanistDiffChangeType::TYPE_MOVE_AWAY) {
            $old->setType(ArcanistDiffChangeType::TYPE_MULTICOPY);
          } else {
            $old->setType(ArcanistDiffChangeType::TYPE_COPY_AWAY);
          }

          $old->addAwayPath($change->getCurrentPath());
        }

        if (!empty($match['move'])) {
          $change->setType(ArcanistDiffChangeType::TYPE_MOVE_HERE);
          $old = $this->buildChange($change->getOldPath());
          $type = $old->getType();

          if ($type == ArcanistDiffChangeType::TYPE_MULTICOPY) {
            // Great, no change.
          } else if ($type == ArcanistDiffChangeType::TYPE_MOVE_AWAY) {
            $old->setType(ArcanistDiffChangeType::TYPE_MULTICOPY);
          } else if ($type == ArcanistDiffChangeType::TYPE_COPY_AWAY) {
            $old->setType(ArcanistDiffChangeType::TYPE_MULTICOPY);
          } else {
            $old->setType(ArcanistDiffChangeType::TYPE_MOVE_AWAY);
          }

          // We'll reference this above.
          $move_source = $old;

          $old->addAwayPath($change->getCurrentPath());
        }

        $line = $this->nextNonemptyLine();
      } while (true);
    }

    $line = $this->getLine();

    if ($is_svn) {
      $ok = preg_match('/^=+\s*$/', $line);
      if (!$ok) {
        $this->didFailParse("Expected '=======================' divider line.");
      } else {
        // Adding an empty file in SVN can produce an empty line here.
        $line = $this->nextNonemptyLine();
      }
    } else if ($is_git) {
      $ok = preg_match('/^index .*$/', $line);
      if (!$ok) {
        // TODO: "hg diff -g" diffs ("mercurial git-style diffs") do not include
        // this line, so we can't parse them if we fail on it. Maybe introduce
        // a flag saying "parse this diff using relaxed git-style diff rules"?

        // $this->didFailParse("Expected 'index af23f...a98bc' header line.");
      } else {
        // NOTE: In the git case, where this patch is the last change in the
        // file, we may have a final terminal newline. Skip over it so that
        // we'll hit the '$line === null' block below. This is covered by the
        // 'git-empty-file.gitdiff' test case.
        $line = $this->nextNonemptyLine();
      }
    }

    // If there are files with only whitespace changes and -b or -w are
    // supplied as command-line flags to `diff', svn and git both produce
    // changes without any body.
    if ($line === null ||
        preg_match(
          '/^(Index:|Property changes on:|diff --git|commit) /',
          $line)) {
      return;
    }

    $is_binary_add = preg_match(
      '/^Cannot display: file marked as a binary type\.$/',
      rtrim($line));
    if ($is_binary_add) {
      $this->nextLine(); // Cannot display: file marked as a binary type.
      $this->nextNonemptyLine(); // svn:mime-type = application/octet-stream
      $this->markBinary($change);
      return;
    }

    // We can get this in git, or in SVN when a file exists in the repository
    // WITHOUT a binary mime-type and is changed and given a binary mime-type.
    $is_binary_diff = preg_match(
      '/^Binary files .* and .* differ$/',
      rtrim($line));
    if ($is_binary_diff) {
      $this->nextNonemptyLine(); // Binary files x and y differ
      $this->markBinary($change);
      return;
    }

    // This occurs under "hg diff --git" when a binary file is removed. See
    // test case "hg-binary-delete.hgdiff". (I believe it never occurs under
    // git, which reports the "files X and /dev/null differ" string above. Git
    // can not apply these patches.)
    $is_hg_binary_delete = preg_match(
      '/^Binary file .* has changed$/',
      rtrim($line));
    if ($is_hg_binary_delete) {
      $this->nextNonemptyLine();
      $this->markBinary($change);
      return;
    }

    // With "git diff --binary" (not a normal mode, but one users may explicitly
    // invoke and then, e.g., copy-paste into the web console) or "hg diff
    // --git" (normal under hg workflows), we may encounter a literal binary
    // patch.
    $is_git_binary_patch = preg_match(
      '/^GIT binary patch$/',
      rtrim($line));
    if ($is_git_binary_patch) {
      $this->nextLine();
      $this->parseGitBinaryPatch();
      $line = $this->getLine();
      if (preg_match('/^literal/', $line)) {
        // We may have old/new binaries (change) or just a new binary (hg add).
        // If there are two blocks, parse both.
        $this->parseGitBinaryPatch();
      }
      $this->markBinary($change);
      return;
    }

    if ($is_git) {
      // "git diff -b" ignores whitespace, but has an empty hunk target
      if (preg_match('@^diff --git .*$@', $line)) {
        $this->nextLine();
        return null;
      }
    }

    $old_file = $this->parseHunkTarget();
    $new_file = $this->parseHunkTarget();

    $change->setOldPath($old_file);

    $this->parseChangeset($change);
  }

  private function parseGitBinaryPatch() {

    // TODO: We could decode the patches, but it's a giant mess so don't bother
    // for now. We'll pick up the data from the working copy in the common
    // case ("arc diff").

    $line = $this->getLine();
    if (!preg_match('/^literal /', $line)) {
      $this->didFailParse("Expected 'literal NNNN' to start git binary patch.");
    }
    do {
      $line = $this->nextLineTrimmed();
      if ($line === '' || $line === null) {
        // Some versions of Mercurial apparently omit the terminal newline,
        // although it's unclear if Git will ever do this. In either case,
        // rely on the base85 check for sanity.
        $this->nextNonemptyLine();
        return;
      } else if (!preg_match('/^[a-zA-Z]/', $line)) {
        $this->didFailParse("Expected base85 line length character (a-zA-Z).");
      }
    } while (true);
  }

  protected function parseHunkTarget() {
    $line = $this->getLine();
    $matches = null;

    $remainder = '(?:\s*\(.*\))?';
    if ($this->getIsMercurial()) {
      // Something like "Fri Aug 26 01:20:50 2005 -0700", don't bother trying
      // to parse it.
      $remainder = '\t.*';
    }

    $ok = preg_match(
      '@^[-+]{3} (?:[ab]/)?(?P<path>.*?)'.$remainder.'$@',
      $line,
      $matches);
    if (!$ok) {
      $this->didFailParse(
        "Expected hunk target '+++ path/to/file.ext (revision N)'.");
    }

    $this->nextLine();
    return $matches['path'];
  }

  protected function markBinary(ArcanistDiffChange $change) {
    $change->setFileType(ArcanistDiffChangeType::FILE_BINARY);
    return $this;
  }

  protected function parseChangeset(ArcanistDiffChange $change) {
    $all_changes = array();
    do {
      $hunk = new ArcanistDiffHunk();
      $line = $this->getLineTrimmed();
      $real = array();

      // In the case where only one line is changed, the length is omitted.
      // The final group is for git, which appends a guess at the function
      // context to the diff.
      $matches = null;
      $ok = preg_match(
        '/^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@(?: .*?)?$/U',
        $line,
        $matches);

      if (!$ok) {
        // It's possible we hit the style of an svn1.7 property change.
        // This is a 4-line Index block, followed by an empty line, followed
        // by a "Property changes on:" section similar to svn1.6.
        if ($line == '') {
          $line = $this->nextNonemptyLine();
          $ok = preg_match('/^Property changes on:/', $line);
          if (!$ok) {
            $this->didFailParse("Confused by empty line");
          }
          $line = $this->nextLine();
          return $this->parsePropertyHunk($change);
        }
        $this->didFailParse("Expected hunk header '@@ -NN,NN +NN,NN @@'.");
      }

      $hunk->setOldOffset($matches[1]);
      $hunk->setNewOffset($matches[3]);

      // Cover for the cases where length wasn't present (implying one line).
      $old_len = idx($matches, 2);
      if (!strlen($old_len)) {
        $old_len = 1;
      }
      $new_len = idx($matches, 4);
      if (!strlen($new_len)) {
        $new_len = 1;
      }

      $hunk->setOldLength($old_len);
      $hunk->setNewLength($new_len);

      $add = 0;
      $del = 0;

      $hit_next_hunk = false;
      while ((($line = $this->nextLine()) !== null)) {
        if (strlen(rtrim($line, "\r\n"))) {
          $char = $line[0];
        } else {
          // Normally, we do not encouter empty lines in diffs, because
          // unchanged lines have an initial space. However, in Git, with
          // the option `diff.suppress-blank-empty` set, unchanged blank lines
          // emit as completely empty. If we encounter a completely empty line,
          // treat it as a ' ' (i.e., unchanged empty line) line.
          $char = ' ';
        }
        switch ($char) {
          case '\\':
            if (!preg_match('@\\ No newline at end of file@', $line)) {
              $this->didFailParse(
                "Expected '\ No newline at end of file'.");
            }
            if ($new_len) {
              $real[] = $line;
              $hunk->setIsMissingOldNewline(true);
            } else {
              $real[] = $line;
              $hunk->setIsMissingNewNewline(true);
            }
            if (!$new_len) {
              break 2;
            }
            break;
          case '+':
            ++$add;
            --$new_len;
            $real[] = $line;
            break;
          case '-':
            if (!$old_len) {
              // In this case, we've hit "---" from a new file. So don't
              // advance the line cursor.
              $hit_next_hunk = true;
              break 2;
            }
            ++$del;
            --$old_len;
            $real[] = $line;
            break;
          case ' ':
            if (!$old_len && !$new_len) {
              break 2;
            }
            --$old_len;
            --$new_len;
            $real[] = $line;
            break;
          default:
            // We hit something, likely another hunk.
            $hit_next_hunk = true;
            break 2;
        }
      }

      if ($old_len || $new_len) {
        $this->didFailParse("Found the wrong number of hunk lines.");
      }

      $corpus = implode('', $real);

      $is_binary = false;
      if ($this->detectBinaryFiles) {
        $is_binary = !phutil_is_utf8($corpus);
        $try_encoding = $this->tryEncoding;

        if ($is_binary && $try_encoding) {
          $is_binary = ArcanistDiffUtils::isHeuristicBinaryFile($corpus);
          if (!$is_binary) {
            $corpus = phutil_utf8_convert($corpus, 'UTF-8', $try_encoding);
            if (!phutil_is_utf8($corpus)) {
              throw new Exception(
                "Failed to convert a hunk from '{$try_encoding}' to UTF-8. ".
                "Check that the specified encoding is correct.");
            }
          }
        }

      }

      if ($is_binary) {
        // SVN happily treats binary files which aren't marked with the right
        // mime type as text files. Detect that junk here and mark the file
        // binary. We'll catch stuff with unicode too, but that's verboten
        // anyway. If there are too many false positives with this we might
        // need to make it threshold-triggered instead of triggering on any
        // unprintable byte.
        $change->setFileType(ArcanistDiffChangeType::FILE_BINARY);
      } else {
        $hunk->setCorpus($corpus);
        $hunk->setAddLines($add);
        $hunk->setDelLines($del);
        $change->addHunk($hunk);
      }

      if (!$hit_next_hunk) {
        $line = $this->nextNonemptyLine();
      }

    } while (preg_match('/^@@ /', $line));
  }

  protected function buildChange($path = null) {
    $change = null;
    if ($path !== null) {
      if (!empty($this->changes[$path])) {
        return $this->changes[$path];
      }
    }

    if ($this->forcePath) {
      return $this->changes[$this->forcePath];
    }

    $change = new ArcanistDiffChange();
    if ($path !== null) {
      $change->setCurrentPath($path);
      $this->changes[$path] = $change;
    } else {
      $this->changes[] = $change;
    }

    return $change;
  }

  protected function didStartParse($text) {
    $this->rawDiff = $text;

    // Eat leading whitespace. This may happen if the first change in the diff
    // is an SVN property change.
    $text = ltrim($text);

    // Try to strip ANSI color codes from colorized diffs. ANSI color codes
    // might be present in two cases:
    //
    //   - You piped a colorized diff into 'arc --raw' or similar (normally
    //     we're able to disable colorization on diffs we control the generation
    //     of).
    //   - You're diffing a file which actually contains ANSI color codes.
    //
    // The former is vastly more likely, but we try to distinguish between the
    // two cases by testing for a color code at the beginning of a line. If
    // we find one, we know it's a colorized diff (since the beginning of the
    // line should be "+", "-" or " " if the code is in the diff text).
    //
    // While it's possible a diff might be colorized and fail this test, it's
    // unlikely, and it covers hg's color extension which seems to be the most
    // stubborn about colorizing text despite stdout not being a TTY.
    //
    // We might incorrectly strip color codes from a colorized diff of a text
    // file with color codes inside it, but this case is stupid and pathological
    // and you've dug your own grave.

    $ansi_color_pattern = '\x1B\[[\d;]*m';
    if (preg_match('/^'.$ansi_color_pattern.'/m', $text)) {
      $text = preg_replace('/'.$ansi_color_pattern.'/', '', $text);
    }

    $this->text = phutil_split_lines($text);
    $this->line = 0;
  }

  protected function getLine() {
    if ($this->text === null) {
      throw new Exception("Not parsing!");
    }
    if (isset($this->text[$this->line])) {
      return $this->text[$this->line];
    }
    return null;
  }

  protected function getLineTrimmed() {
    $line = $this->getLine();
    if ($line !== null) {
      $line = trim($line, "\r\n");
    }
    return $line;
  }

  protected function nextLine() {
    $this->line++;
    return $this->getLine();
  }

  protected function nextLineTrimmed() {
    $line = $this->nextLine();
    if ($line !== null) {
      $line = trim($line, "\r\n");
    }
    return $line;
  }

  protected function nextNonemptyLine() {
    while (($line = $this->nextLine()) !== null) {
      if (strlen(trim($line)) !== 0) {
        break;
      }
    }
    return $this->getLine();
  }

  protected function nextLineThatLooksLikeDiffStart() {
    while (($line = $this->nextLine()) !== null) {
      if (preg_match('/^\s*diff\s+-(?:r|-git)/', $line)) {
        break;
      }
    }
    return $this->getLine();
  }

  protected function saveLine() {
    $this->lineSaved = $this->line;
  }

  protected function restoreLine() {
    $this->line = $this->lineSaved;
  }

  protected function isFirstNonEmptyLine() {
    $count = count($this->text);
    for ($i = 0; $i < $count; $i++) {
      if (strlen(trim($this->text[$i])) != 0) {
        return ($i == $this->line);
      }
    }
    // Entire file is empty.
    return false;
  }

  protected function didFinishParse() {
    $this->text = null;
  }

  public function setWriteDiffOnFailure($write) {
    $this->writeDiffOnFailure = $write;
    return $this;
  }

  protected function didFailParse($message) {
    $context = 5;
    $min = max(0, $this->line - $context);
    $max = min($this->line + $context, count($this->text) - 1);

    $context = '';
    for ($ii = $min; $ii <= $max; $ii++) {
      $context .= sprintf(
        "%8.8s %6.6s   %s",
        ($ii == $this->line) ? '>>>  ' : '',
        $ii + 1,
        $this->text[$ii]);
    }

    $out = array();
    $out[] = "Diff Parse Exception: {$message}";

    if ($this->writeDiffOnFailure) {
      $temp = new TempFile();
      $temp->setPreserveFile(true);

      Filesystem::writeFile($temp, $this->rawDiff);
      $out[] = "Raw input file was written to: ".(string)$temp;
    }

    $out[] = $context;
    $out = implode("\n\n", $out);

    throw new Exception($out);
  }

  /**
   * Unescape escaped filenames, e.g. from "git diff".
   */
  private static function unescapeFilename($name) {
    if (preg_match('/^".+"$/', $name)) {
      return stripcslashes(substr($name, 1, -1));
    } else {
      return $name;
    }
  }

  private function loadSyntheticData() {
    if (!$this->changes) {
      return;
    }

    $repository_api = $this->repositoryAPI;
    if (!$repository_api) {
      return;
    }

    $changes = $this->changes;
    foreach ($changes as $change) {
      $path = $change->getCurrentPath();

      // Certain types of changes (moves and copies) don't contain change data
      // when expressed in raw "git diff" form. Augment any such diffs with
      // textual data.
      if ($change->getNeedsSyntheticGitHunks() &&
          ($repository_api instanceof ArcanistGitAPI)) {
        $diff = $repository_api->getRawDiffText($path, $moves = false);

        // NOTE: We're reusing the parser and it doesn't reset change state
        // between parses because there's an oddball SVN workflow in Phabricator
        // which relies on being able to inject changes.
        // TODO: Fix this.
        $parser = clone $this;
        $parser->setChanges(array());
        $raw_changes = $parser->parseDiff($diff);

        foreach ($raw_changes as $raw_change) {
          if ($raw_change->getCurrentPath() == $path) {
            $change->setFileType($raw_change->getFileType());
            foreach ($raw_change->getHunks() as $hunk) {
              // Git thinks that this file has been added. But we know that it
              // has been moved or copied without a change.
              $hunk->setCorpus(
                preg_replace('/^\+/m', ' ', $hunk->getCorpus()));
              $change->addHunk($hunk);
            }
            break;
          }
        }

        $change->setNeedsSyntheticGitHunks(false);
      }

      if ($change->getFileType() != ArcanistDiffChangeType::FILE_BINARY &&
          $change->getFileType() != ArcanistDiffChangeType::FILE_IMAGE) {
        continue;
      }

      $change->setOriginalFileData($repository_api->getOriginalFileData($path));
      $change->setCurrentFileData($repository_api->getCurrentFileData($path));
    }

    $this->changes = $changes;
  }


  /**
   * Strip prefixes off paths from `git diff`. By default git uses a/ and b/,
   * but you can set `diff.mnemonicprefix` to get a different set of prefixes,
   * or use `--no-prefix`, `--src-prefix` or `--dst-prefix` to set these to
   * other arbitrary values.
   *
   * We strip the default and mnemonic prefixes, and trust the user knows what
   * they're doing in the other cases.
   *
   * @param   string Path to strip.
   * @return  string Stripped path.
   */
  public static function stripGitPathPrefix($path) {

    static $regex;
    if ($regex === null) {
      $prefixes = array(
        // These are the defaults.
        'a/',
        'b/',

        // These show up when you set "diff.mnemonicprefix".
        'i/',
        'c/',
        'w/',
        'o/',
        '1/',
        '2/',
      );

      foreach ($prefixes as $key => $prefix) {
        $prefixes[$key] = preg_quote($prefix, '@');
      }
      $regex = '@^('.implode('|', $prefixes).')@S';
    }

    return preg_replace($regex, '', $path);
  }


  /**
   * Split the paths on a "diff --git" line into old and new paths. This
   * is difficult because they may be ambiguous if the files contain spaces.
   *
   * @param string Text from a diff line after "diff --git ".
   * @return pair<string, string> Old and new paths.
   */
  public static function splitGitDiffPaths($paths) {
    $matches = null;
    $paths = rtrim($paths, "\r\n");

    $patterns = array(
      // Try quoted paths, used for unicode filenames or filenames with quotes.
      '@^(?P<old>"(?:\\\\.|[^"\\\\]+)+") (?P<new>"(?:\\\\.|[^"\\\\]+)+")$@',

      // Try paths without spaces.
      '@^(?P<old>[^ ]+) (?P<new>[^ ]+)$@',

      // Try paths with well-known prefixes.
      '@^(?P<old>[abicwo12]/.*) (?P<new>[abicwo12]/.*)$@',

      // Try the exact same string twice in a row separated by a space.
      // This can hit a false positive for moves from files like "old file old"
      // to "file", but such a case combined with custom diff prefixes is
      // incredibly obscure.
      '@^(?P<old>.*) (?P<new>\\1)$@',
    );

    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $paths, $matches)) {
        break;
      }
    }

    if (!$matches) {
      throw new Exception(
        "Input diff contains ambiguous line 'diff --git {$paths}'. This line ".
        "is ambiguous because there are spaces in the file names, so the ".
        "parser can not determine where the file names begin and end. To ".
        "resolve this ambiguity, use standard prefixes ('a/' and 'b/') when ".
        "generating diffs.");
    }

    $old = $matches['old'];
    $old = self::unescapeFilename($old);
    $old = self::stripGitPathPrefix($old);

    $new = $matches['new'];
    $new = self::unescapeFilename($new);
    $new = self::stripGitPathPrefix($new);

    return array($old, $new);
  }

}
