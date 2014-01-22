<?php

/**
 * @task discover   Discovering Repositories
 * @task svn        Discovering Subversion Repositories
 * @task git        Discovering Git Repositories
 * @task hg         Discovering Mercurial Repositories
 * @task internal   Internals
 */
final class PhabricatorRepositoryDiscoveryEngine
  extends PhabricatorRepositoryEngine {

  private $repairMode;
  private $commitCache = array();
  const MAX_COMMIT_CACHE_SIZE = 2048;


/* -(  Discovering Repositories  )------------------------------------------- */


  public function setRepairMode($repair_mode) {
    $this->repairMode = $repair_mode;
    return $this;
  }


  public function getRepairMode() {
    return $this->repairMode;
  }


  /**
   * @task discovery
   */
  public function discoverCommits() {
    $repository = $this->getRepository();

    $vcs = $repository->getVersionControlSystem();
    switch ($vcs) {
      case PhabricatorRepositoryType::REPOSITORY_TYPE_SVN:
        $refs = $this->discoverSubversionCommits();
        break;
      case PhabricatorRepositoryType::REPOSITORY_TYPE_MERCURIAL:
        $refs = $this->discoverMercurialCommits();
        break;
      case PhabricatorRepositoryType::REPOSITORY_TYPE_GIT:
        $refs = $this->discoverGitCommits();
        break;
      default:
        throw new Exception("Unknown VCS '{$vcs}'!");
    }

    // Record discovered commits and mark them in the cache.
    foreach ($refs as $ref) {
      $this->recordCommit(
        $repository,
        $ref->getIdentifier(),
        $ref->getEpoch(),
        $ref->getCanCloseImmediately());

      $this->commitCache[$ref->getIdentifier()] = true;
    }

    return $refs;
  }


/* -(  Discovering Git Repositories  )--------------------------------------- */


  /**
   * @task git
   */
  private function discoverGitCommits() {
    $repository = $this->getRepository();

    if (!$repository->isHosted()) {
      $this->verifyGitOrigin($repository);
    }

    $branches = id(new DiffusionLowLevelGitRefQuery())
      ->setRepository($repository)
      ->withIsOriginBranch(true)
      ->execute();

    if (!$branches) {
      // This repository has no branches at all, so we don't need to do
      // anything. Generally, this means the repository is empty.
      return array();
    }

    $branches = $this->sortBranches($branches);
    $branches = mpull($branches, 'getCommitIdentifier', 'getShortName');

    $this->log(
      pht(
        'Discovering commits in repository %s.',
        $repository->getCallsign()));

    $refs = array();
    foreach ($branches as $name => $commit) {
      $this->log(pht('Examining branch "%s", at "%s".', $name, $commit));

      if (!$repository->shouldTrackBranch($name)) {
        $this->log(pht("Skipping, branch is untracked."));
        continue;
      }

      if ($this->isKnownCommit($commit)) {
        $this->log(pht("Skipping, HEAD is known."));
        continue;
      }

      $this->log(pht("Looking for new commits."));

      $refs[] = $this->discoverStreamAncestry(
        new PhabricatorGitGraphStream($repository, $commit),
        $commit,
        $repository->shouldAutocloseBranch($name));
    }

    return array_mergev($refs);
  }


  /**
   * Verify that the "origin" remote exists, and points at the correct URI.
   *
   * This catches or corrects some types of misconfiguration, and also repairs
   * an issue where Git 1.7.1 does not create an "origin" for `--bare` clones.
   * See T4041.
   *
   * @param   PhabricatorRepository Repository to verify.
   * @return  void
   */
  private function verifyGitOrigin(PhabricatorRepository $repository) {
    list($remotes) = $repository->execxLocalCommand(
      'remote show -n origin');

    $matches = null;
    if (!preg_match('/^\s*Fetch URL:\s*(.*?)\s*$/m', $remotes, $matches)) {
      throw new Exception(
        "Expected 'Fetch URL' in 'git remote show -n origin'.");
    }

    $remote_uri = $matches[1];
    $expect_remote = $repository->getRemoteURI();

    if ($remote_uri == "origin") {
      // If a remote does not exist, git pretends it does and prints out a
      // made up remote where the URI is the same as the remote name. This is
      // definitely not correct.

      // Possibly, we should use `git remote --verbose` instead, which does not
      // suffer from this problem (but is a little more complicated to parse).
      $valid = false;
      $exists = false;
    } else {
      $normal_type_git = PhabricatorRepositoryURINormalizer::TYPE_GIT;

      $remote_normal = id(new PhabricatorRepositoryURINormalizer(
        $normal_type_git,
        $remote_uri))->getNormalizedPath();

      $expect_normal = id(new PhabricatorRepositoryURINormalizer(
        $normal_type_git,
        $expect_remote))->getNormalizedPath();

      $valid = ($remote_normal == $expect_normal);
      $exists = true;
    }

    if (!$valid) {
      if (!$exists) {
        // If there's no "origin" remote, just create it regardless of how
        // strongly we own the working copy. There is almost no conceivable
        // scenario in which this could do damage.
        $this->log(
          pht(
            'Remote "origin" does not exist. Creating "origin", with '.
            'URI "%s".',
            $expect_remote));
        $repository->execxLocalCommand(
          'remote add origin %P',
          $repository->getRemoteURIEnvelope());

        // NOTE: This doesn't fetch the origin (it just creates it), so we won't
        // know about origin branches until the next "pull" happens. That's fine
        // for our purposes, but might impact things in the future.
      } else {
        if ($repository->canDestroyWorkingCopy()) {
          // Bad remote, but we can try to repair it.
          $this->log(
            pht(
              'Remote "origin" exists, but is pointed at the wrong URI, "%s". '.
              'Resetting origin URI to "%s.',
              $remote_uri,
              $expect_remote));
          $repository->execxLocalCommand(
            'remote set-url origin %P',
            $repository->getRemoteURIEnvelope());
        } else {
          // Bad remote and we aren't comfortable repairing it.
          $message = pht(
            'Working copy at "%s" has a mismatched origin URI, "%s". '.
            'The expected origin URI is "%s". Fix your configuration, or '.
            'set the remote URI correctly. To avoid breaking anything, '.
            'Phabricator will not automatically fix this.',
            $repository->getLocalPath(),
            $remote_uri,
            $expect_remote);
          throw new Exception($message);
        }
      }
    }
  }


/* -(  Discovering Subversion Repositories  )-------------------------------- */


  /**
   * @task svn
   */
  private function discoverSubversionCommits() {
    $repository = $this->getRepository();

    if (!$repository->isHosted()) {
      $this->verifySubversionRoot($repository);
    }

    $upper_bound = null;
    $limit = 1;
    $refs = array();
    do {
      // Find all the unknown commits on this path. Note that we permit
      // importing an SVN subdirectory rather than the entire repository, so
      // commits may be nonsequential.

      if ($upper_bound === null) {
        $at_rev = 'HEAD';
      } else {
        $at_rev = ($upper_bound - 1);
      }

      try {
        list($xml, $stderr) = $repository->execxRemoteCommand(
          'log --xml --quiet --limit %d %s',
          $limit,
          $repository->getSubversionBaseURI($at_rev));
      } catch (CommandException $ex) {
        $stderr = $ex->getStdErr();
        if (preg_match('/(path|File) not found/', $stderr)) {
          // We've gone all the way back through history and this path was not
          // affected by earlier commits.
          break;
        }
        throw $ex;
      }

      $xml = phutil_utf8ize($xml);
      $log = new SimpleXMLElement($xml);
      foreach ($log->logentry as $entry) {
        $identifier = (int)$entry['revision'];
        $epoch = (int)strtotime((string)$entry->date[0]);
        $refs[$identifier] = id(new PhabricatorRepositoryCommitRef())
          ->setIdentifier($identifier)
          ->setEpoch($epoch)
          ->setCanCloseImmediately(true);

        if ($upper_bound === null) {
          $upper_bound = $identifier;
        } else {
          $upper_bound = min($upper_bound, $identifier);
        }
      }

      // Discover 2, 4, 8, ... 256 logs at a time. This allows us to initially
      // import large repositories fairly quickly, while pulling only as much
      // data as we need in the common case (when we've already imported the
      // repository and are just grabbing one commit at a time).
      $limit = min($limit * 2, 256);

    } while ($upper_bound > 1 && !$this->isKnownCommit($upper_bound));

    krsort($refs);
    while ($refs && $this->isKnownCommit(last($refs)->getIdentifier())) {
      array_pop($refs);
    }
    $refs = array_reverse($refs);

    return $refs;
  }


  private function verifySubversionRoot(PhabricatorRepository $repository) {
    list($xml) = $repository->execxRemoteCommand(
      'info --xml %s',
      $repository->getSubversionPathURI());

    $xml = phutil_utf8ize($xml);
    $xml = new SimpleXMLElement($xml);

    $remote_root = (string)($xml->entry[0]->repository[0]->root[0]);
    $expect_root = $repository->getSubversionPathURI();

    $normal_type_svn = PhabricatorRepositoryURINormalizer::TYPE_SVN;

    $remote_normal = id(new PhabricatorRepositoryURINormalizer(
      $normal_type_svn,
      $remote_root))->getNormalizedPath();

    $expect_normal = id(new PhabricatorRepositoryURINormalizer(
      $normal_type_svn,
      $expect_root))->getNormalizedPath();

    if ($remote_normal != $expect_normal) {
      throw new Exception(
        pht(
          'Repository "%s" does not have a correctly configured remote URI. '.
          'The remote URI for a Subversion repository MUST point at the '.
          'repository root. The root for this repository is "%s", but the '.
          'configured URI is "%s". To resolve this error, set the remote URI '.
          'to point at the repository root. If you want to import only part '.
          'of a Subversion repository, use the "Import Only" option.',
          $repository->getCallsign(),
          $remote_root,
          $expect_root));
    }
  }


/* -(  Discovering Mercurial Repositories  )--------------------------------- */


  /**
   * @task hg
   */
  private function discoverMercurialCommits() {
    $repository = $this->getRepository();

    $branches = id(new DiffusionLowLevelMercurialBranchesQuery())
      ->setRepository($repository)
      ->execute();

    $refs = array();
    foreach ($branches as $branch) {
      // NOTE: Mercurial branches may have multiple heads, so the names may
      // not be unique.
      $name = $branch->getShortName();
      $commit = $branch->getCommitIdentifier();

      $this->log(pht('Examining branch "%s" head "%s".', $name, $commit));
      if (!$repository->shouldTrackBranch($name)) {
        $this->log(pht("Skipping, branch is untracked."));
        continue;
      }

      if ($this->isKnownCommit($commit)) {
        $this->log(pht("Skipping, this head is a known commit."));
        continue;
      }

      $this->log(pht("Looking for new commits."));

      $refs[] = $this->discoverStreamAncestry(
        new PhabricatorMercurialGraphStream($repository, $commit),
        $commit,
        $close_immediately = true);
    }

    return array_mergev($refs);
  }


/* -(  Internals  )---------------------------------------------------------- */


  private function discoverStreamAncestry(
    PhabricatorRepositoryGraphStream $stream,
    $commit,
    $close_immediately) {

    $discover = array($commit);
    $graph = array();
    $seen = array();

    // Find all the reachable, undiscovered commits. Build a graph of the
    // edges.
    while ($discover) {
      $target = array_pop($discover);

      if (empty($graph[$target])) {
        $graph[$target] = array();
      }

      $parents = $stream->getParents($target);
      foreach ($parents as $parent) {
        if ($this->isKnownCommit($parent)) {
          continue;
        }

        $graph[$target][$parent] = true;

        if (empty($seen[$parent])) {
          $seen[$parent] = true;
          $discover[] = $parent;
        }
      }
    }

    // Now, sort them topographically.
    $commits = $this->reduceGraph($graph);

    $refs = array();
    foreach ($commits as $commit) {
      $refs[] = id(new PhabricatorRepositoryCommitRef())
        ->setIdentifier($commit)
        ->setEpoch($stream->getCommitDate($commit))
        ->setCanCloseImmediately($close_immediately);
    }

    return $refs;
  }


  private function reduceGraph(array $edges) {
    foreach ($edges as $commit => $parents) {
      $edges[$commit] = array_keys($parents);
    }

    $graph = new PhutilDirectedScalarGraph();
    $graph->addNodes($edges);

    $commits = $graph->getTopographicallySortedNodes();

    // NOTE: We want the most ancestral nodes first, so we need to reverse the
    // list we get out of AbstractDirectedGraph.
    $commits = array_reverse($commits);

    return $commits;
  }


  private function isKnownCommit($identifier) {
    if (isset($this->commitCache[$identifier])) {
      return true;
    }

    if ($this->repairMode) {
      // In repair mode, rediscover the entire repository, ignoring the
      // database state. We can hit the local cache above, but if we miss it
      // stop the script from going to the database cache.
      return false;
    }

    $commit = id(new PhabricatorRepositoryCommit())->loadOneWhere(
      'repositoryID = %d AND commitIdentifier = %s',
      $this->getRepository()->getID(),
      $identifier);

    if (!$commit) {
      return false;
    }

    $this->commitCache[$identifier] = true;
    while (count($this->commitCache) > self::MAX_COMMIT_CACHE_SIZE) {
      array_shift($this->commitCache);
    }

    return true;
  }


  /**
   * Sort branches so we process closeable branches first. This makes the
   * whole import process a little cheaper, since we can close these commits
   * the first time through rather than catching them in the refs step.
   *
   * @task internal
   *
   * @param   list<DiffusionRepositoryRef> List of branch heads.
   * @return  list<DiffusionRepositoryRef> Sorted list of branch heads.
   */
  private function sortBranches(array $branches) {
    $repository = $this->getRepository();

    $head_branches = array();
    $tail_branches = array();
    foreach ($branches as $branch) {
      $name = $branch->getShortName();

      if ($repository->shouldAutocloseBranch($name)) {
        $head_branches[] = $branch;
      } else {
        $tail_branches[] = $branch;
      }
    }

    return array_merge($head_branches, $tail_branches);
  }


  private function recordCommit(
    PhabricatorRepository $repository,
    $commit_identifier,
    $epoch,
    $close_immediately) {

    $commit = new PhabricatorRepositoryCommit();
    $commit->setRepositoryID($repository->getID());
    $commit->setCommitIdentifier($commit_identifier);
    $commit->setEpoch($epoch);
    if ($close_immediately) {
      $commit->setImportStatus(PhabricatorRepositoryCommit::IMPORTED_CLOSEABLE);
    }

    $data = new PhabricatorRepositoryCommitData();

    try {
      $commit->openTransaction();
        $commit->save();
        $data->setCommitID($commit->getID());
        $data->save();
      $commit->saveTransaction();

      $this->insertTask($repository, $commit);

      queryfx(
        $repository->establishConnection('w'),
        'INSERT INTO %T (repositoryID, size, lastCommitID, epoch)
          VALUES (%d, 1, %d, %d)
          ON DUPLICATE KEY UPDATE
            size = size + 1,
            lastCommitID =
              IF(VALUES(epoch) > epoch, VALUES(lastCommitID), lastCommitID),
            epoch = IF(VALUES(epoch) > epoch, VALUES(epoch), epoch)',
        PhabricatorRepository::TABLE_SUMMARY,
        $repository->getID(),
        $commit->getID(),
        $epoch);

      if ($this->repairMode) {
        // Normally, the query should throw a duplicate key exception. If we
        // reach this in repair mode, we've actually performed a repair.
        $this->log(pht('Repaired commit "%s".', $commit_identifier));
      }

      PhutilEventEngine::dispatchEvent(
        new PhabricatorEvent(
          PhabricatorEventType::TYPE_DIFFUSION_DIDDISCOVERCOMMIT,
          array(
            'repository'  => $repository,
            'commit'      => $commit,
          )));

    } catch (AphrontQueryDuplicateKeyException $ex) {
      $commit->killTransaction();
      // Ignore. This can happen because we discover the same new commit
      // more than once when looking at history, or because of races or
      // data inconsistency or cosmic radiation; in any case, we're still
      // in a good state if we ignore the failure.
    }
  }

  private function insertTask(
    PhabricatorRepository $repository,
    PhabricatorRepositoryCommit $commit,
    $data = array()) {

    $vcs = $repository->getVersionControlSystem();
    switch ($vcs) {
      case PhabricatorRepositoryType::REPOSITORY_TYPE_GIT:
        $class = 'PhabricatorRepositoryGitCommitMessageParserWorker';
        break;
      case PhabricatorRepositoryType::REPOSITORY_TYPE_SVN:
        $class = 'PhabricatorRepositorySvnCommitMessageParserWorker';
        break;
      case PhabricatorRepositoryType::REPOSITORY_TYPE_MERCURIAL:
        $class = 'PhabricatorRepositoryMercurialCommitMessageParserWorker';
        break;
      default:
        throw new Exception("Unknown repository type '{$vcs}'!");
    }

    $data['commitID'] = $commit->getID();

    PhabricatorWorker::scheduleTask($class, $data);
  }

}
