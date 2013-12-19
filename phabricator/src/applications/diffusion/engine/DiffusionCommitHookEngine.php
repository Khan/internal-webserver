<?php

/**
 * @task config   Configuring the Hook Engine
 * @task hook     Hook Execution
 * @task git      Git Hooks
 * @task hg       Mercurial Hooks
 * @task svn      Subversion Hooks
 * @task internal Internals
 */
final class DiffusionCommitHookEngine extends Phobject {

  const ENV_USER = 'PHABRICATOR_USER';
  const ENV_REMOTE_ADDRESS = 'PHABRICATOR_REMOTE_ADDRESS';
  const ENV_REMOTE_PROTOCOL = 'PHABRICATOR_REMOTE_PROTOCOL';

  const EMPTY_HASH = '0000000000000000000000000000000000000000';

  private $viewer;
  private $repository;
  private $stdin;
  private $subversionTransaction;
  private $subversionRepository;
  private $remoteAddress;
  private $remoteProtocol;
  private $transactionKey;
  private $mercurialHook;
  private $mercurialCommits = array();

  private $heraldViewerProjects;


/* -(  Config  )------------------------------------------------------------- */


  public function setRemoteProtocol($remote_protocol) {
    $this->remoteProtocol = $remote_protocol;
    return $this;
  }

  public function getRemoteProtocol() {
    return $this->remoteProtocol;
  }

  public function setRemoteAddress($remote_address) {
    $this->remoteAddress = $remote_address;
    return $this;
  }

  public function getRemoteAddress() {
    return $this->remoteAddress;
  }

  private function getRemoteAddressForLog() {
    // If whatever we have here isn't a valid IPv4 address, just store `null`.
    // Older versions of PHP return `-1` on failure instead of `false`.
    $remote_address = $this->getRemoteAddress();
    $remote_address = max(0, ip2long($remote_address));
    $remote_address = nonempty($remote_address, null);
    return $remote_address;
  }

  private function getTransactionKey() {
    if (!$this->transactionKey) {
      $entropy = Filesystem::readRandomBytes(64);
      $this->transactionKey = PhabricatorHash::digestForIndex($entropy);
    }
    return $this->transactionKey;
  }

  public function setSubversionTransactionInfo($transaction, $repository) {
    $this->subversionTransaction = $transaction;
    $this->subversionRepository = $repository;
    return $this;
  }

  public function setStdin($stdin) {
    $this->stdin = $stdin;
    return $this;
  }

  public function getStdin() {
    return $this->stdin;
  }

  public function setRepository(PhabricatorRepository $repository) {
    $this->repository = $repository;
    return $this;
  }

  public function getRepository() {
    return $this->repository;
  }

  public function setViewer(PhabricatorUser $viewer) {
    $this->viewer = $viewer;
    return $this;
  }

  public function getViewer() {
    return $this->viewer;
  }

  public function setMercurialHook($mercurial_hook) {
    $this->mercurialHook = $mercurial_hook;
    return $this;
  }

  public function getMercurialHook() {
    return $this->mercurialHook;
  }


/* -(  Hook Execution  )----------------------------------------------------- */


  public function execute() {
    $ref_updates = $this->findRefUpdates();
    $all_updates = $ref_updates;

    $caught = null;
    try {

      try {
        $this->rejectDangerousChanges($ref_updates);
      } catch (DiffusionCommitHookRejectException $ex) {
        // If we're rejecting dangerous changes, flag everything that we've
        // seen as rejected so it's clear that none of it was accepted.
        foreach ($all_updates as $update) {
          $update->setRejectCode(
            PhabricatorRepositoryPushLog::REJECT_DANGEROUS);
        }
        throw $ex;
      }

      $this->applyHeraldRefRules($ref_updates, $all_updates);

      $content_updates = $this->findContentUpdates($ref_updates);
      $all_updates = array_merge($all_updates, $content_updates);

      $this->applyHeraldContentRules($content_updates, $all_updates);

      // TODO: Fire external hooks.

      // If we make it this far, we're accepting these changes. Mark all the
      // logs as accepted.
      foreach ($all_updates as $update) {
        $update->setRejectCode(PhabricatorRepositoryPushLog::REJECT_ACCEPT);
      }
    } catch (Exception $ex) {
      // We'll throw this again in a minute, but we want to save all the logs
      // first.
      $caught = $ex;
    }

    // Save all the logs no matter what the outcome was.
    foreach ($all_updates as $update) {
      $update->save();
    }

    if ($caught) {
      throw $caught;
    }

    return 0;
  }

  private function findRefUpdates() {
    $type = $this->getRepository()->getVersionControlSystem();
    switch ($type) {
      case PhabricatorRepositoryType::REPOSITORY_TYPE_GIT:
        return $this->findGitRefUpdates();
      case PhabricatorRepositoryType::REPOSITORY_TYPE_MERCURIAL:
        return $this->findMercurialRefUpdates();
      case PhabricatorRepositoryType::REPOSITORY_TYPE_SVN:
        return $this->findSubversionRefUpdates();
      default:
        throw new Exception(pht('Unsupported repository type "%s"!', $type));
    }
  }

  private function rejectDangerousChanges(array $ref_updates) {
    assert_instances_of($ref_updates, 'PhabricatorRepositoryPushLog');

    $repository = $this->getRepository();
    if ($repository->shouldAllowDangerousChanges()) {
      return;
    }

    $flag_dangerous = PhabricatorRepositoryPushLog::CHANGEFLAG_DANGEROUS;

    foreach ($ref_updates as $ref_update) {
      if (!$ref_update->hasChangeFlags($flag_dangerous)) {
        // This is not a dangerous change.
        continue;
      }

      // We either have a branch deletion or a non fast-forward branch update.
      // Format a message and reject the push.

      $message = pht(
        "DANGEROUS CHANGE: %s\n".
        "Dangerous change protection is enabled for this repository.\n".
        "Edit the repository configuration before making dangerous changes.",
        $ref_update->getDangerousChangeDescription());

      throw new DiffusionCommitHookRejectException($message);
    }
  }

  private function findContentUpdates(array $ref_updates) {
    assert_instances_of($ref_updates, 'PhabricatorRepositoryPushLog');

    $type = $this->getRepository()->getVersionControlSystem();
    switch ($type) {
      case PhabricatorRepositoryType::REPOSITORY_TYPE_GIT:
        return $this->findGitContentUpdates($ref_updates);
      case PhabricatorRepositoryType::REPOSITORY_TYPE_MERCURIAL:
        return $this->findMercurialContentUpdates($ref_updates);
      case PhabricatorRepositoryType::REPOSITORY_TYPE_SVN:
        return $this->findSubversionContentUpdates($ref_updates);
      default:
        throw new Exception(pht('Unsupported repository type "%s"!', $type));
    }
  }


/* -(  Herald  )------------------------------------------------------------- */

  private function applyHeraldRefRules(
    array $ref_updates,
    array $all_updates) {
    $this->applyHeraldRules(
      $ref_updates,
      new HeraldPreCommitRefAdapter(),
      $all_updates);
  }

  private function applyHeraldContentRules(
    array $content_updates,
    array $all_updates) {
    $this->applyHeraldRules(
      $content_updates,
      new HeraldPreCommitContentAdapter(),
      $all_updates);
  }

  private function applyHeraldRules(
    array $updates,
    HeraldAdapter $adapter_template,
    array $all_updates) {

    if (!$updates) {
      return;
    }

    $adapter_template->setHookEngine($this);

    $engine = new HeraldEngine();
    $rules = null;
    $blocking_effect = null;
    foreach ($updates as $update) {
      $adapter = id(clone $adapter_template)
        ->setPushLog($update);

      if ($rules === null) {
        $rules = $engine->loadRulesForAdapter($adapter);
      }

      $effects = $engine->applyRules($rules, $adapter);
      $engine->applyEffects($effects, $adapter, $rules);
      $xscript = $engine->getTranscript();

      if ($blocking_effect === null) {
        foreach ($effects as $effect) {
          if ($effect->getAction() == HeraldAdapter::ACTION_BLOCK) {
            $blocking_effect = $effect;
            break;
          }
        }
      }
    }

    if ($blocking_effect) {
      foreach ($all_updates as $update) {
        $update->setRejectCode(PhabricatorRepositoryPushLog::REJECT_HERALD);
        $update->setRejectDetails($blocking_effect->getRulePHID());
      }

      $message = $blocking_effect->getTarget();
      if (!strlen($message)) {
        $message = pht('(None.)');
      }

      $rules = mpull($rules, null, 'getID');
      $rule = idx($rules, $effect->getRuleID());
      if ($rule && strlen($rule->getName())) {
        $rule_name = $rule->getName();
      } else {
        $rule_name = pht('Unnamed Herald Rule');
      }

      throw new DiffusionCommitHookRejectException(
        pht(
          "This commit was rejected by Herald pre-commit rule %s.\n".
          "Rule: %s\n".
          "Reason: %s",
          'H'.$blocking_effect->getRuleID(),
          $rule_name,
          $message));
    }
  }

  public function loadViewerProjectPHIDsForHerald() {
    // This just caches the viewer's projects so we don't need to load them
    // over and over again when applying Herald rules.
    if ($this->heraldViewerProjects === null) {
      $this->heraldViewerProjects = id(new PhabricatorProjectQuery())
        ->setViewer($this->getViewer())
        ->withMemberPHIDs(array($this->getViewer()->getPHID()))
        ->execute();
    }

    return mpull($this->heraldViewerProjects, 'getPHID');
  }


/* -(  Git  )---------------------------------------------------------------- */


  private function findGitRefUpdates() {
    $ref_updates = array();

    // First, parse stdin, which lists all the ref changes. The input looks
    // like this:
    //
    //   <old hash> <new hash> <ref>

    $stdin = $this->getStdin();
    $lines = phutil_split_lines($stdin, $retain_endings = false);
    foreach ($lines as $line) {
      $parts = explode(' ', $line, 3);
      if (count($parts) != 3) {
        throw new Exception(pht('Expected "old new ref", got "%s".', $line));
      }

      $ref_old = $parts[0];
      $ref_new = $parts[1];
      $ref_raw = $parts[2];

      if (preg_match('(^refs/heads/)', $ref_raw)) {
        $ref_type = PhabricatorRepositoryPushLog::REFTYPE_BRANCH;
        $ref_raw = substr($ref_raw, strlen('refs/heads/'));
      } else if (preg_match('(^refs/tags/)', $ref_raw)) {
        $ref_type = PhabricatorRepositoryPushLog::REFTYPE_TAG;
        $ref_raw = substr($ref_raw, strlen('refs/tags/'));
      } else {
        throw new Exception(
          pht(
            "Unable to identify the reftype of '%s'. Rejecting push.",
            $ref_raw));
      }

      $ref_update = $this->newPushLog()
        ->setRefType($ref_type)
        ->setRefName($ref_raw)
        ->setRefOld($ref_old)
        ->setRefNew($ref_new);

      $ref_updates[] = $ref_update;
    }

    $this->findGitMergeBases($ref_updates);
    $this->findGitChangeFlags($ref_updates);

    return $ref_updates;
  }


  private function findGitMergeBases(array $ref_updates) {
    assert_instances_of($ref_updates, 'PhabricatorRepositoryPushLog');

    $futures = array();
    foreach ($ref_updates as $key => $ref_update) {
      // If the old hash is "00000...", the ref is being created (either a new
      // branch, or a new tag). If the new hash is "00000...", the ref is being
      // deleted. If both are nonempty, the ref is being updated. For updates,
      // we'll figure out the `merge-base` of the old and new objects here. This
      // lets us reject non-FF changes cheaply; later, we'll figure out exactly
      // which commits are new.
      $ref_old = $ref_update->getRefOld();
      $ref_new = $ref_update->getRefNew();

      if (($ref_old === self::EMPTY_HASH) ||
          ($ref_new === self::EMPTY_HASH)) {
        continue;
      }

      $futures[$key] = $this->getRepository()->getLocalCommandFuture(
        'merge-base %s %s',
        $ref_old,
        $ref_new);
    }

    foreach (Futures($futures)->limit(8) as $key => $future) {

      // If 'old' and 'new' have no common ancestors (for example, a force push
      // which completely rewrites a ref), `git merge-base` will exit with
      // an error and no output. It would be nice to find a positive test
      // for this instead, but I couldn't immediately come up with one. See
      // T4224. Assume this means there are no ancestors.

      list($err, $stdout) = $future->resolve();

      if ($err) {
        $merge_base = null;
      } else {
        $merge_base = rtrim($stdout, "\n");
      }

      $ref_update->setMergeBase($merge_base);
    }

    return $ref_updates;
  }


  private function findGitChangeFlags(array $ref_updates) {
    assert_instances_of($ref_updates, 'PhabricatorRepositoryPushLog');

    foreach ($ref_updates as $key => $ref_update) {
      $ref_old = $ref_update->getRefOld();
      $ref_new = $ref_update->getRefNew();
      $ref_type = $ref_update->getRefType();

      $ref_flags = 0;
      $dangerous = null;

      if ($ref_old === self::EMPTY_HASH) {
        $ref_flags |= PhabricatorRepositoryPushLog::CHANGEFLAG_ADD;
      } else if ($ref_new === self::EMPTY_HASH) {
        $ref_flags |= PhabricatorRepositoryPushLog::CHANGEFLAG_DELETE;
        if ($ref_type == PhabricatorRepositoryPushLog::REFTYPE_BRANCH) {
          $ref_flags |= PhabricatorRepositoryPushLog::CHANGEFLAG_DANGEROUS;
          $dangerous = pht(
            "The change you're attempting to push deletes the branch '%s'.",
            $ref_update->getRefName());
        }
      } else {
        $merge_base = $ref_update->getMergeBase();
        if ($merge_base == $ref_old) {
          // This is a fast-forward update to an existing branch.
          // These are safe.
          $ref_flags |= PhabricatorRepositoryPushLog::CHANGEFLAG_APPEND;
        } else {
          $ref_flags |= PhabricatorRepositoryPushLog::CHANGEFLAG_REWRITE;

          // For now, we don't consider deleting or moving tags to be a
          // "dangerous" update. It's way harder to get wrong and should be easy
          // to recover from once we have better logging. Only add the dangerous
          // flag if this ref is a branch.

          if ($ref_type == PhabricatorRepositoryPushLog::REFTYPE_BRANCH) {
            $ref_flags |= PhabricatorRepositoryPushLog::CHANGEFLAG_DANGEROUS;

            $dangerous = pht(
              "The change you're attempting to push updates the branch '%s' ".
              "from '%s' to '%s', but this is not a fast-forward. Pushes ".
              "which rewrite published branch history are dangerous.",
              $ref_update->getRefName(),
              $ref_update->getRefOldShort(),
              $ref_update->getRefNewShort());
          }
        }
      }

      $ref_update->setChangeFlags($ref_flags);
      if ($dangerous !== null) {
        $ref_update->attachDangerousChangeDescription($dangerous);
      }
    }

    return $ref_updates;
  }


  private function findGitContentUpdates(array $ref_updates) {
    $flag_delete = PhabricatorRepositoryPushLog::CHANGEFLAG_DELETE;

    $futures = array();
    foreach ($ref_updates as $key => $ref_update) {
      if ($ref_update->hasChangeFlags($flag_delete)) {
        // Deleting a branch or tag can never create any new commits.
        continue;
      }

      // NOTE: This piece of magic finds all new commits, by walking backward
      // from the new value to the value of *any* existing ref in the
      // repository. Particularly, this will cover the cases of a new branch, a
      // completely moved tag, etc.
      $futures[$key] = $this->getRepository()->getLocalCommandFuture(
        'log --format=%s %s --not --all',
        '%H',
        $ref_update->getRefNew());
    }

    $content_updates = array();
    foreach (Futures($futures)->limit(8) as $key => $future) {
      list($stdout) = $future->resolvex();

      if (!strlen(trim($stdout))) {
        // This change doesn't have any new commits. One common case of this
        // is creating a new tag which points at an existing commit.
        continue;
      }

      $commits = phutil_split_lines($stdout, $retain_newlines = false);

      foreach ($commits as $commit) {
        $content_updates[$commit] = $this->newPushLog()
          ->setRefType(PhabricatorRepositoryPushLog::REFTYPE_COMMIT)
          ->setRefNew($commit)
          ->setChangeFlags(PhabricatorRepositoryPushLog::CHANGEFLAG_ADD);
      }
    }

    return $content_updates;
  }


/* -(  Mercurial  )---------------------------------------------------------- */


  private function findMercurialRefUpdates() {
    $hook = $this->getMercurialHook();
    switch ($hook) {
      case 'pretxnchangegroup':
        return $this->findMercurialChangegroupRefUpdates();
      case 'prepushkey':
        return $this->findMercurialPushKeyRefUpdates();
      default:
        throw new Exception(pht('Unrecognized hook "%s"!', $hook));
    }
  }

  private function findMercurialChangegroupRefUpdates() {
    $hg_node = getenv('HG_NODE');
    if (!$hg_node) {
      throw new Exception(pht('Expected HG_NODE in environment!'));
    }

    // NOTE: We need to make sure this is passed to subprocesses, or they won't
    // be able to see new commits. Mercurial uses this as a marker to determine
    // whether the pending changes are visible or not.
    $_ENV['HG_PENDING'] = getenv('HG_PENDING');
    $repository = $this->getRepository();

    $futures = array();

    foreach (array('old', 'new') as $key) {
      $futures[$key] = $repository->getLocalCommandFuture(
        'heads --template %s',
        '{node}\1{branches}\2');
    }
    // Wipe HG_PENDING out of the old environment so we see the pre-commit
    // state of the repository.
    $futures['old']->updateEnv('HG_PENDING', null);

    $futures['commits'] = $repository->getLocalCommandFuture(
      "log --rev %s --rev tip --template %s",
      hgsprintf('%s', $hg_node),
      '{node}\1{branches}\2');

    // Resolve all of the futures now. We don't need the 'commits' future yet,
    // but it simplifies the logic to just get it out of the way.
    foreach (Futures($futures) as $future) {
      $future->resolvex();
    }

    list($commit_raw) = $futures['commits']->resolvex();
    $commit_map = $this->parseMercurialCommits($commit_raw);
    $this->mercurialCommits = $commit_map;

    list($old_raw) = $futures['old']->resolvex();
    $old_refs = $this->parseMercurialHeads($old_raw);

    list($new_raw) = $futures['new']->resolvex();
    $new_refs = $this->parseMercurialHeads($new_raw);

    $all_refs = array_keys($old_refs + $new_refs);

    $ref_updates = array();
    foreach ($all_refs as $ref) {
      $old_heads = idx($old_refs, $ref, array());
      $new_heads = idx($new_refs, $ref, array());

      sort($old_heads);
      sort($new_heads);

      if ($old_heads === $new_heads) {
        // No changes to this branch, so skip it.
        continue;
      }

      if (!$new_heads) {
        if ($old_heads) {
          // It looks like this push deletes a branch, but that isn't possible
          // in Mercurial, so something is going wrong here. Bail out.
          throw new Exception(
            pht(
              'Mercurial repository has no new head for branch "%s" after '.
              'push. This is unexpected; rejecting change.'));
        } else {
          // Obviously, this should never be possible either, as it makes
          // no sense. Explode.
          throw new Exception(
            pht(
              'Mercurial repository has no new or old heads for branch "%s" '.
              'after push. This makes no sense; rejecting change.'));
        }
      }

      $stray_heads = array();
      if (count($old_heads) > 1) {
        // HORRIBLE: In Mercurial, branches can have multiple heads. If the
        // old branch had multiple heads, we need to figure out which new
        // heads descend from which old heads, so we can tell whether you're
        // actively creating new heads (dangerous) or just working in a
        // repository that's already full of garbage (strongly discouraged but
        // not as inherently dangerous). These cases should be very uncommon.

        $dfutures = array();
        foreach ($old_heads as $old_head) {
          $dfutures[$old_head] = $repository->getLocalCommandFuture(
            'log --rev %s --template %s',
            hgsprintf('(descendants(%s) and head())', $old_head),
            '{node}\1');
        }

        $head_map = array();
        foreach (Futures($dfutures) as $future_head => $dfuture) {
          list($stdout) = $dfuture->resolvex();
          $head_map[$future_head] = array_filter(explode("\1", $stdout));
        }

        // Now, find all the new stray heads this push creates, if any. These
        // are new heads which do not descend from the old heads.
        $seen = array_fuse(array_mergev($head_map));
        foreach ($new_heads as $new_head) {
          if (empty($seen[$new_head])) {
            $head_map[self::EMPTY_HASH][] = $new_head;
          }
        }
      } else if ($old_heads) {
        $head_map[head($old_heads)] = $new_heads;
      } else {
        $head_map[self::EMPTY_HASH] = $new_heads;
      }

      foreach ($head_map as $old_head => $child_heads) {
        foreach ($child_heads as $new_head) {
          if ($new_head === $old_head) {
            continue;
          }

          $ref_flags = 0;
          $dangerous = null;
          if ($old_head == self::EMPTY_HASH) {
            $ref_flags |= PhabricatorRepositoryPushLog::CHANGEFLAG_ADD;
          } else {
            $ref_flags |= PhabricatorRepositoryPushLog::CHANGEFLAG_APPEND;
          }

          $splits_existing_head = (count($child_heads) > 1);
          $creates_duplicate_head = ($old_head == self::EMPTY_HASH) &&
                                    (count($head_map) > 1);

          if ($splits_existing_head || $creates_duplicate_head) {
            $readable_child_heads = array();
            foreach ($child_heads as $child_head) {
              $readable_child_heads[] = substr($child_head, 0, 12);
            }

            $ref_flags |= PhabricatorRepositoryPushLog::CHANGEFLAG_DANGEROUS;

            if ($splits_existing_head) {
              // We're splitting an existing head into two or more heads.
              // This is dangerous, and a super bad idea. Note that we're only
              // raising this if you're actively splitting a branch head. If a
              // head split in the past, we don't consider appends to it
              // to be dangerous.
              $dangerous = pht(
                "The change you're attempting to push splits the head of ".
                "branch '%s' into multiple heads: %s. This is inadvisable ".
                "and dangerous.",
                $ref,
                implode(', ', $readable_child_heads));
            } else {
              // We're adding a second (or more) head to a branch. The new
              // head is not a descendant of any old head.
              $dangerous = pht(
                "The change you're attempting to push creates new, divergent ".
                "heads for the branch '%s': %s. This is inadvisable and ".
                "dangerous.",
                $ref,
                implode(', ', $readable_child_heads));
            }
          }

          $ref_update = $this->newPushLog()
            ->setRefType(PhabricatorRepositoryPushLog::REFTYPE_BRANCH)
            ->setRefName($ref)
            ->setRefOld($old_head)
            ->setRefNew($new_head)
            ->setChangeFlags($ref_flags);

          if ($dangerous !== null) {
            $ref_update->attachDangerousChangeDescription($dangerous);
          }

          $ref_updates[] = $ref_update;
        }
      }
    }

    return $ref_updates;
  }

  private function findMercurialPushKeyRefUpdates() {
    $key_namespace = getenv('HG_NAMESPACE');

    if ($key_namespace === 'phases') {
      // Mercurial changes commit phases as part of normal push operations. We
      // just ignore these, as they don't seem to represent anything
      // interesting.
      return array();
    }

    $key_name = getenv('HG_KEY');

    $key_old = getenv('HG_OLD');
    if (!strlen($key_old)) {
      $key_old = null;
    }

    $key_new = getenv('HG_NEW');
    if (!strlen($key_new)) {
      $key_new = null;
    }

    if ($key_namespace !== 'bookmarks') {
      throw new Exception(
        pht(
          "Unknown Mercurial key namespace '%s', with key '%s' (%s -> %s). ".
          "Rejecting push.",
          $key_namespace,
          $key_name,
          coalesce($key_old, pht('null')),
          coalesce($key_new, pht('null'))));
    }

    if ($key_old === $key_new) {
      // We get a callback when the bookmark doesn't change. Just ignore this,
      // as it's a no-op.
      return array();
    }

    $ref_flags = 0;
    $merge_base = null;
    if ($key_old === null) {
      $ref_flags |= PhabricatorRepositoryPushLog::CHANGEFLAG_ADD;
    } else if ($key_new === null) {
      $ref_flags |= PhabricatorRepositoryPushLog::CHANGEFLAG_DELETE;
    } else {
      list($merge_base_raw) = $this->getRepository()->execxLocalCommand(
        'log --template %s --rev %s',
        '{node}',
        hgsprintf('ancestor(%s, %s)', $key_old, $key_new));

      if (strlen(trim($merge_base_raw))) {
        $merge_base = trim($merge_base_raw);
      }

      if ($merge_base && ($merge_base === $key_old)) {
        $ref_flags |= PhabricatorRepositoryPushLog::CHANGEFLAG_APPEND;
      } else {
        $ref_flags |= PhabricatorRepositoryPushLog::CHANGEFLAG_REWRITE;
      }
    }

    $ref_update = $this->newPushLog()
      ->setRefType(PhabricatorRepositoryPushLog::REFTYPE_BOOKMARK)
      ->setRefName($key_name)
      ->setRefOld(coalesce($key_old, self::EMPTY_HASH))
      ->setRefNew(coalesce($key_new, self::EMPTY_HASH))
      ->setChangeFlags($ref_flags);

    return array($ref_update);
  }

  private function findMercurialContentUpdates(array $ref_updates) {
    $content_updates = array();

    foreach ($this->mercurialCommits as $commit => $branches) {
      $content_updates[$commit] = $this->newPushLog()
        ->setRefType(PhabricatorRepositoryPushLog::REFTYPE_COMMIT)
        ->setRefNew($commit)
        ->setChangeFlags(PhabricatorRepositoryPushLog::CHANGEFLAG_ADD);
    }

    return $content_updates;
  }

  private function parseMercurialCommits($raw) {
    $commits_lines = explode("\2", $raw);
    $commits_lines = array_filter($commits_lines);
    $commit_map = array();
    foreach ($commits_lines as $commit_line) {
      list($node, $branches_raw) = explode("\1", $commit_line);

      if (!strlen($branches_raw)) {
        $branches = array('default');
      } else {
        $branches = explode(' ', $branches_raw);
      }

      $commit_map[$node] = $branches;
    }

    return $commit_map;
  }

  private function parseMercurialHeads($raw) {
    $heads_map = $this->parseMercurialCommits($raw);

    $heads = array();
    foreach ($heads_map as $commit => $branches) {
      foreach ($branches as $branch) {
        $heads[$branch][] = $commit;
      }
    }

    return $heads;
  }


/* -(  Subversion  )--------------------------------------------------------- */


  private function findSubversionRefUpdates() {
    // Subversion doesn't have any kind of mutable ref metadata.
    return array();
  }

  private function findSubversionContentUpdates(array $ref_updates) {
    list($youngest) = execx(
      'svnlook youngest %s',
      $this->subversionRepository);
    $ref_new = (int)$youngest + 1;

    $ref_flags = 0;
    $ref_flags |= PhabricatorRepositoryPushLog::CHANGEFLAG_ADD;
    $ref_flags |= PhabricatorRepositoryPushLog::CHANGEFLAG_APPEND;

    $ref_content = $this->newPushLog()
      ->setRefType(PhabricatorRepositoryPushLog::REFTYPE_COMMIT)
      ->setRefNew($ref_new)
      ->setChangeFlags($ref_flags);

    return array($ref_content);
  }


/* -(  Internals  )---------------------------------------------------------- */


  private function newPushLog() {
    // NOTE: By default, we create these with REJECT_BROKEN as the reject
    // code. This indicates a broken hook, and covers the case where we
    // encounter some unexpected exception and consequently reject the changes.

    // NOTE: We generate PHIDs up front so the Herald transcripts can pick them
    // up.
    $phid = id(new PhabricatorRepositoryPushLog())->generatePHID();

    return PhabricatorRepositoryPushLog::initializeNewLog($this->getViewer())
      ->setPHID($phid)
      ->attachRepository($this->getRepository())
      ->setRepositoryPHID($this->getRepository()->getPHID())
      ->setEpoch(time())
      ->setRemoteAddress($this->getRemoteAddressForLog())
      ->setRemoteProtocol($this->getRemoteProtocol())
      ->setTransactionKey($this->getTransactionKey())
      ->setRejectCode(PhabricatorRepositoryPushLog::REJECT_BROKEN)
      ->setRejectDetails(null);
  }

  public function loadChangesetsForCommit($identifier) {
    $vcs = $this->getRepository()->getVersionControlSystem();
    switch ($vcs) {
      case PhabricatorRepositoryType::REPOSITORY_TYPE_GIT:
      case PhabricatorRepositoryType::REPOSITORY_TYPE_MERCURIAL:
        // For git and hg, we can use normal commands.
        $drequest = DiffusionRequest::newFromDictionary(
          array(
            'repository' => $this->getRepository(),
            'user' => $this->getViewer(),
            'commit' => $identifier,
          ));
        $raw_diff = DiffusionRawDiffQuery::newFromDiffusionRequest($drequest)
          ->setTimeout(5 * 60)
          ->setLinesOfContext(0)
          ->loadRawDiff();
        break;
      case PhabricatorRepositoryType::REPOSITORY_TYPE_SVN:
        // TODO: This diff has 3 lines of context, which produces slightly
        // incorrect "added file content" and "removed file content" results.
        // This may also choke on binaries, but "svnlook diff" does not support
        // the "--diff-cmd" flag.

        // For subversion, we need to use `svnlook`.
        list($raw_diff) = execx(
          'svnlook diff -t %s %s',
          $this->subversionTransaction,
          $this->subversionRepository);
        break;
      default:
        throw new Exception(pht("Unknown VCS '%s!'", $vcs));
    }

    $parser = new ArcanistDiffParser();
    $changes = $parser->parseDiff($raw_diff);
    $diff = DifferentialDiff::newFromRawChanges($changes);
    return $diff->getChangesets();
  }

  public function loadCommitRefForCommit($identifier) {
    $repository = $this->getRepository();
    $vcs = $repository->getVersionControlSystem();
    switch ($vcs) {
      case PhabricatorRepositoryType::REPOSITORY_TYPE_GIT:
        return id(new DiffusionLowLevelGitCommitQuery())
          ->setRepository($repository)
          ->withIdentifier($identifier)
          ->execute();
      case PhabricatorRepositoryType::REPOSITORY_TYPE_MERCURIAL:
        return id(new DiffusionLowLevelMercurialCommitQuery())
          ->setRepository($repository)
          ->withIdentifier($identifier)
          ->execute();
      case PhabricatorRepositoryType::REPOSITORY_TYPE_SVN:
        // For subversion, we need to use `svnlook`.
        list($message) = execx(
          'svnlook log -t %s %s',
          $this->subversionTransaction,
          $this->subversionRepository);

        return id(new DiffusionCommitRef())
          ->setMessage($message);
        break;
      default:
        throw new Exception(pht("Unknown VCS '%s!'", $vcs));
    }
  }


}
