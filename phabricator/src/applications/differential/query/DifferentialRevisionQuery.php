<?php

/**
 * Flexible query API for Differential revisions. Example:
 *
 *   // Load open revisions
 *   $revisions = id(new DifferentialRevisionQuery())
 *     ->withStatus(DifferentialRevisionQuery::STATUS_OPEN)
 *     ->execute();
 *
 * @task config   Query Configuration
 * @task exec     Query Execution
 * @task internal Internals
 */
final class DifferentialRevisionQuery
  extends PhabricatorCursorPagedPolicyAwareQuery {

  private $pathIDs = array();

  private $status             = 'status-any';
  const STATUS_ANY            = 'status-any';
  const STATUS_OPEN           = 'status-open';
  const STATUS_ACCEPTED       = 'status-accepted';
  const STATUS_NEEDS_REVIEW   = 'status-needs-review';
  const STATUS_NEEDS_REVISION = 'status-needs-revision';
  const STATUS_CLOSED         = 'status-closed';
  const STATUS_ABANDONED      = 'status-abandoned';

  private $authors = array();
  private $draftAuthors = array();
  private $ccs = array();
  private $reviewers = array();
  private $revIDs = array();
  private $commitHashes = array();
  private $phids = array();
  private $responsibles = array();
  private $branches = array();
  private $arcanistProjectPHIDs = array();
  private $repositoryPHIDs;

  private $order            = 'order-modified';
  const ORDER_MODIFIED      = 'order-modified';
  const ORDER_CREATED       = 'order-created';
  /**
   * This is essentially a denormalized copy of the revision modified time that
   * should perform better for path queries with a LIMIT. Critically, when you
   * browse "/", every revision in that repository for all time will match so
   * the query benefits from being able to stop before fully materializing the
   * result set.
   */
  const ORDER_PATH_MODIFIED = 'order-path-modified';

  private $needRelationships  = false;
  private $needActiveDiffs    = false;
  private $needDiffIDs        = false;
  private $needCommitPHIDs    = false;
  private $needHashes         = false;
  private $needReviewerStatus = false;
  private $needReviewerAuthority;
  private $needDrafts;
  private $needFlags;

  private $buildingGlobalOrder;


/* -(  Query Configuration  )------------------------------------------------ */


  /**
   * Filter results to revisions which affect a Diffusion path ID in a given
   * repository. You can call this multiple times to select revisions for
   * several paths.
   *
   * @param int Diffusion repository ID.
   * @param int Diffusion path ID.
   * @return this
   * @task config
   */
  public function withPath($repository_id, $path_id) {
    $this->pathIDs[] = array(
      'repositoryID' => $repository_id,
      'pathID'       => $path_id,
    );
    return $this;
  }

  /**
   * Filter results to revisions authored by one of the given PHIDs. Calling
   * this function will clear anything set by previous calls to
   * @{method:withAuthors}.
   *
   * @param array List of PHIDs of authors
   * @return this
   * @task config
   */
  public function withAuthors(array $author_phids) {
    $this->authors = $author_phids;
    return $this;
  }

  /**
   * Filter results to revisions with comments authored bythe given PHIDs
   *
   * @param array List of PHIDs of authors
   * @return this
   * @task config
   */
  public function withDraftRepliesByAuthors(array $author_phids) {
    $this->draftAuthors = $author_phids;
    return $this;
  }

  /**
   * Filter results to revisions which CC one of the listed people. Calling this
   * function will clear anything set by previous calls to @{method:withCCs}.
   *
   * @param array List of PHIDs of subscribers.
   * @return this
   * @task config
   */
  public function withCCs(array $cc_phids) {
    $this->ccs = $cc_phids;
    return $this;
  }

  /**
   * Filter results to revisions that have one of the provided PHIDs as
   * reviewers. Calling this function will clear anything set by previous calls
   * to @{method:withReviewers}.
   *
   * @param array List of PHIDs of reviewers
   * @return this
   * @task config
   */
  public function withReviewers(array $reviewer_phids) {
    $this->reviewers = $reviewer_phids;
    return $this;
  }

  /**
   * Filter results to revisions that have one of the provided commit hashes.
   * Calling this function will clear anything set by previous calls to
   * @{method:withCommitHashes}.
   *
   * @param array List of pairs <Class
   *              ArcanistDifferentialRevisionHash::HASH_$type constant,
   *              hash>
   * @return this
   * @task config
   */
  public function withCommitHashes(array $commit_hashes) {
    $this->commitHashes = $commit_hashes;
    return $this;
  }

  /**
   * Filter results to revisions with a given status. Provide a class constant,
   * such as ##DifferentialRevisionQuery::STATUS_OPEN##.
   *
   * @param const Class STATUS constant, like STATUS_OPEN.
   * @return this
   * @task config
   */
  public function withStatus($status_constant) {
    $this->status = $status_constant;
    return $this;
  }


  /**
   * Filter results to revisions on given branches.
   *
   * @param  list List of branch names.
   * @return this
   * @task config
   */
  public function withBranches(array $branches) {
    $this->branches = $branches;
    return $this;
  }


  /**
   * Filter results to only return revisions whose ids are in the given set.
   *
   * @param array List of revision ids
   * @return this
   * @task config
   */
  public function withIDs(array $ids) {
    $this->revIDs = $ids;
    return $this;
  }


  /**
   * Filter results to only return revisions whose PHIDs are in the given set.
   *
   * @param array List of revision PHIDs
   * @return this
   * @task config
   */
  public function withPHIDs(array $phids) {
    $this->phids = $phids;
    return $this;
  }


  /**
   * Given a set of users, filter results to return only revisions they are
   * responsible for (i.e., they are either authors or reviewers).
   *
   * @param array List of user PHIDs.
   * @return this
   * @task config
   */
  public function withResponsibleUsers(array $responsible_phids) {
    $this->responsibles = $responsible_phids;
    return $this;
  }


  /**
   * Filter results to only return revisions with a given set of arcanist
   * projects.
   *
   * @param array List of project PHIDs.
   * @return this
   * @task config
   */
  public function withArcanistProjectPHIDs(array $arc_project_phids) {
    $this->arcanistProjectPHIDs = $arc_project_phids;
    return $this;
  }

  public function withRepositoryPHIDs(array $repository_phids) {
    $this->repositoryPHIDs = $repository_phids;
    return $this;
  }


  /**
   * Set result ordering. Provide a class constant, such as
   * ##DifferentialRevisionQuery::ORDER_CREATED##.
   *
   * @task config
   */
  public function setOrder($order_constant) {
    $this->order = $order_constant;
    return $this;
  }


  /**
   * Set whether or not the query will load and attach relationships.
   *
   * @param bool True to load and attach relationships.
   * @return this
   * @task config
   */
  public function needRelationships($need_relationships) {
    $this->needRelationships = $need_relationships;
    return $this;
  }


  /**
   * Set whether or not the query should load the active diff for each
   * revision.
   *
   * @param bool True to load and attach diffs.
   * @return this
   * @task config
   */
  public function needActiveDiffs($need_active_diffs) {
    $this->needActiveDiffs = $need_active_diffs;
    return $this;
  }


  /**
   * Set whether or not the query should load the associated commit PHIDs for
   * each revision.
   *
   * @param bool True to load and attach diffs.
   * @return this
   * @task config
   */
  public function needCommitPHIDs($need_commit_phids) {
    $this->needCommitPHIDs = $need_commit_phids;
    return $this;
  }


  /**
   * Set whether or not the query should load associated diff IDs for each
   * revision.
   *
   * @param bool True to load and attach diff IDs.
   * @return this
   * @task config
   */
  public function needDiffIDs($need_diff_ids) {
    $this->needDiffIDs = $need_diff_ids;
    return $this;
  }


  /**
   * Set whether or not the query should load associated commit hashes for each
   * revision.
   *
   * @param bool True to load and attach commit hashes.
   * @return this
   * @task config
   */
  public function needHashes($need_hashes) {
    $this->needHashes = $need_hashes;
    return $this;
  }


  /**
   * Set whether or not the query should load associated reviewer status.
   *
   * @param bool True to load and attach reviewers.
   * @return this
   * @task config
   */
  public function needReviewerStatus($need_reviewer_status) {
    $this->needReviewerStatus = $need_reviewer_status;
    return $this;
  }


  /**
   * Request information about the viewer's authority to act on behalf of each
   * reviewer. In particular, they have authority to act on behalf of projects
   * they are a member of.
   *
   * @param bool True to load and attach authority.
   * @return this
   * @task config
   */
  public function needReviewerAuthority($need_reviewer_authority) {
    $this->needReviewerAuthority = $need_reviewer_authority;
    return $this;
  }

  public function needFlags($need_flags) {
    $this->needFlags = $need_flags;
    return $this;
  }

  public function needDrafts($need_drafts) {
    $this->needDrafts = $need_drafts;
    return $this;
  }


/* -(  Query Execution  )---------------------------------------------------- */


  /**
   * Execute the query as configured, returning matching
   * @{class:DifferentialRevision} objects.
   *
   * @return list List of matching DifferentialRevision objects.
   * @task exec
   */
  public function loadPage() {
    $table = new DifferentialRevision();
    $conn_r = $table->establishConnection('r');

    $data = $this->loadData();

    return $table->loadAllFromArray($data);
  }

  public function willFilterPage(array $revisions) {
    $viewer = $this->getViewer();

    $repository_phids = mpull($revisions, 'getRepositoryPHID');
    $repository_phids = array_filter($repository_phids);

    $repositories = array();
    if ($repository_phids) {
      $repositories = id(new PhabricatorRepositoryQuery())
        ->setViewer($this->getViewer())
        ->withPHIDs($repository_phids)
        ->execute();
      $repositories = mpull($repositories, null, 'getPHID');
    }

    // If a revision is associated with a repository:
    //
    //   - the viewer must be able to see the repository; or
    //   - the viewer must have an automatic view capability.
    //
    // In the latter case, we'll load the revision but not load the repository.

    $can_view = PhabricatorPolicyCapability::CAN_VIEW;
    foreach ($revisions as $key => $revision) {
      $repo_phid = $revision->getRepositoryPHID();
      if (!$repo_phid) {
        // The revision has no associated repository. Attach `null` and move on.
        $revision->attachRepository(null);
        continue;
      }

      $repository = idx($repositories, $repo_phid);
      if ($repository) {
        // The revision has an associated repository, and the viewer can see
        // it. Attach it and move on.
        $revision->attachRepository($repository);
        continue;
      }

      if ($revision->hasAutomaticCapability($can_view, $viewer)) {
        // The revision has an associated repository which the viewer can not
        // see, but the viewer has an automatic capability on this revision.
        // Load the revision without attaching a repository.
        $revision->attachRepository(null);
        continue;
      }

      if ($this->getViewer()->isOmnipotent()) {
        // The viewer is omnipotent. Allow the revision to load even without
        // a repository.
        $revision->attachRepository(null);
        continue;
      }

      // The revision has an associated repository, and the viewer can't see
      // it, and the viewer has no special capabilities. Filter out this
      // revision.
      $this->didRejectResult($revision);
      unset($revisions[$key]);
    }

    if (!$revisions) {
      return array();
    }

    $table = new DifferentialRevision();
    $conn_r = $table->establishConnection('r');

    if ($this->needRelationships) {
      $this->loadRelationships($conn_r, $revisions);
    }

    if ($this->needCommitPHIDs) {
      $this->loadCommitPHIDs($conn_r, $revisions);
    }

    $need_active = $this->needActiveDiffs;
    $need_ids = $need_active || $this->needDiffIDs;

    if ($need_ids) {
      $this->loadDiffIDs($conn_r, $revisions);
    }

    if ($need_active) {
      $this->loadActiveDiffs($conn_r, $revisions);
    }

    if ($this->needHashes) {
      $this->loadHashes($conn_r, $revisions);
    }

    if ($this->needReviewerStatus || $this->needReviewerAuthority) {
      $this->loadReviewers($conn_r, $revisions);
    }

    return $revisions;
  }

  protected function didFilterPage(array $revisions) {
    $viewer = $this->getViewer();

    if ($this->needFlags) {
      $flags = id(new PhabricatorFlagQuery())
        ->setViewer($viewer)
        ->withOwnerPHIDs(array($viewer->getPHID()))
        ->withObjectPHIDs(mpull($revisions, 'getPHID'))
        ->execute();
      $flags = mpull($flags, null, 'getObjectPHID');
      foreach ($revisions as $revision) {
        $revision->attachFlag(
          $viewer,
          idx($flags, $revision->getPHID()));
      }
    }

    if ($this->needDrafts) {
      $drafts = id(new DifferentialDraft())->loadAllWhere(
        'authorPHID = %s AND objectPHID IN (%Ls)',
        $viewer->getPHID(),
        mpull($revisions, 'getPHID'));
      $drafts = mgroup($drafts, 'getObjectPHID');
      foreach ($revisions as $revision) {
        $revision->attachDrafts(
          $viewer,
          idx($drafts, $revision->getPHID(), array()));
      }
    }

    return $revisions;
  }

  private function loadData() {
    $table = new DifferentialRevision();
    $conn_r = $table->establishConnection('r');

    $selects = array();

    // NOTE: If the query includes "responsiblePHIDs", we execute it as a
    // UNION of revisions they own and revisions they're reviewing. This has
    // much better performance than doing it with JOIN/WHERE.
    if ($this->responsibles) {
      $basic_authors = $this->authors;
      $basic_reviewers = $this->reviewers;

      $authority_projects = id(new PhabricatorProjectQuery())
        ->setViewer($this->getViewer())
        ->withMemberPHIDs($this->responsibles)
        ->execute();
      $authority_phids = mpull($authority_projects, 'getPHID');

      try {
        // Build the query where the responsible users are authors.
        $this->authors = array_merge($basic_authors, $this->responsibles);
        $this->reviewers = $basic_reviewers;
        $selects[] = $this->buildSelectStatement($conn_r);

        // Build the query where the responsible users are reviewers, or
        // projects they are members of are reviewers.
        $this->authors = $basic_authors;
        $this->reviewers = array_merge(
          $basic_reviewers,
          $this->responsibles,
          $authority_phids);
        $selects[] = $this->buildSelectStatement($conn_r);

        // Put everything back like it was.
        $this->authors = $basic_authors;
        $this->reviewers = $basic_reviewers;
      } catch (Exception $ex) {
        $this->authors = $basic_authors;
        $this->reviewers = $basic_reviewers;
        throw $ex;
      }
    } else {
      $selects[] = $this->buildSelectStatement($conn_r);
    }

    if (count($selects) > 1) {
      $this->buildingGlobalOrder = true;
      $query = qsprintf(
        $conn_r,
        '%Q %Q %Q',
        implode(' UNION DISTINCT ', $selects),
        $this->buildOrderClause($conn_r),
        $this->buildLimitClause($conn_r));
    } else {
      $query = head($selects);
    }

    return queryfx_all($conn_r, '%Q', $query);
  }

  private function buildSelectStatement(AphrontDatabaseConnection $conn_r) {
    $table = new DifferentialRevision();

    $select = qsprintf(
      $conn_r,
      'SELECT r.* FROM %T r',
      $table->getTableName());

    $joins = $this->buildJoinsClause($conn_r);
    $where = $this->buildWhereClause($conn_r);
    $group_by = $this->buildGroupByClause($conn_r);

    $this->buildingGlobalOrder = false;
    $order_by = $this->buildOrderClause($conn_r);

    $limit = $this->buildLimitClause($conn_r);

    return qsprintf(
      $conn_r,
      '(%Q %Q %Q %Q %Q %Q)',
      $select,
      $joins,
      $where,
      $group_by,
      $order_by,
      $limit);
  }


/* -(  Internals  )---------------------------------------------------------- */


  /**
   * @task internal
   */
  private function buildJoinsClause($conn_r) {
    $joins = array();
    if ($this->pathIDs) {
      $path_table = new DifferentialAffectedPath();
      $joins[] = qsprintf(
        $conn_r,
        'JOIN %T p ON p.revisionID = r.id',
        $path_table->getTableName());
    }

    if ($this->commitHashes) {
      $joins[] = qsprintf(
        $conn_r,
        'JOIN %T hash_rel ON hash_rel.revisionID = r.id',
        ArcanistDifferentialRevisionHash::TABLE_NAME);
    }

    if ($this->ccs) {
      $joins[] = qsprintf(
        $conn_r,
        'JOIN %T e_ccs ON e_ccs.src = r.phid '.
        'AND e_ccs.type = %s '.
        'AND e_ccs.dst in (%Ls)',
        PhabricatorEdgeConfig::TABLE_NAME_EDGE,
        PhabricatorEdgeConfig::TYPE_OBJECT_HAS_SUBSCRIBER,
        $this->ccs);
    }

    if ($this->reviewers) {
      $joins[] = qsprintf(
        $conn_r,
        'JOIN %T e_reviewers ON e_reviewers.src = r.phid '.
        'AND e_reviewers.type = %s '.
        'AND e_reviewers.dst in (%Ls)',
        PhabricatorEdgeConfig::TABLE_NAME_EDGE,
        PhabricatorEdgeConfig::TYPE_DREV_HAS_REVIEWER,
        $this->reviewers);
    }

    if ($this->draftAuthors) {
      $differential_draft = new DifferentialDraft();
      $joins[] = qsprintf(
        $conn_r,
        'JOIN %T has_draft ON has_draft.objectPHID = r.phid '.
        'AND has_draft.authorPHID IN (%Ls)',
        $differential_draft->getTableName(),
        $this->draftAuthors);
    }

    $joins = implode(' ', $joins);

    return $joins;
  }


  /**
   * @task internal
   */
  private function buildWhereClause($conn_r) {
    $where = array();

    if ($this->pathIDs) {
      $path_clauses = array();
      $repo_info = igroup($this->pathIDs, 'repositoryID');
      foreach ($repo_info as $repository_id => $paths) {
        $path_clauses[] = qsprintf(
          $conn_r,
          '(p.repositoryID = %d AND p.pathID IN (%Ld))',
          $repository_id,
          ipull($paths, 'pathID'));
      }
      $path_clauses = '('.implode(' OR ', $path_clauses).')';
      $where[] = $path_clauses;
    }

    if ($this->authors) {
      $where[] = qsprintf(
        $conn_r,
        'r.authorPHID IN (%Ls)',
        $this->authors);
    }

    if ($this->revIDs) {
      $where[] = qsprintf(
        $conn_r,
        'r.id IN (%Ld)',
        $this->revIDs);
    }

    if ($this->repositoryPHIDs) {
      $where[] = qsprintf(
        $conn_r,
        'r.repositoryPHID IN (%Ls)',
        $this->repositoryPHIDs);
    }

    if ($this->commitHashes) {
      $hash_clauses = array();
      foreach ($this->commitHashes as $info) {
        list($type, $hash) = $info;
        $hash_clauses[] = qsprintf(
          $conn_r,
          '(hash_rel.type = %s AND hash_rel.hash = %s)',
          $type,
          $hash);
      }
      $hash_clauses = '('.implode(' OR ', $hash_clauses).')';
      $where[] = $hash_clauses;
    }

    if ($this->phids) {
      $where[] = qsprintf(
        $conn_r,
        'r.phid IN (%Ls)',
        $this->phids);
    }

    if ($this->branches) {
      $where[] = qsprintf(
        $conn_r,
        'r.branchName in (%Ls)',
        $this->branches);
    }

    if ($this->arcanistProjectPHIDs) {
      $where[] = qsprintf(
        $conn_r,
        'r.arcanistProjectPHID in (%Ls)',
        $this->arcanistProjectPHIDs);
    }

    switch ($this->status) {
      case self::STATUS_ANY:
        break;
      case self::STATUS_OPEN:
        $where[] = qsprintf(
          $conn_r,
          'r.status IN (%Ld)',
          DifferentialRevisionStatus::getOpenStatuses());
        break;
      case self::STATUS_NEEDS_REVIEW:
        $where[] = qsprintf(
          $conn_r,
          'r.status IN (%Ld)',
          array(
               ArcanistDifferentialRevisionStatus::NEEDS_REVIEW,
          ));
        break;
      case self::STATUS_NEEDS_REVISION:
        $where[] = qsprintf(
          $conn_r,
          'r.status IN (%Ld)',
          array(
               ArcanistDifferentialRevisionStatus::NEEDS_REVISION,
          ));
        break;
      case self::STATUS_ACCEPTED:
        $where[] = qsprintf(
          $conn_r,
          'r.status IN (%Ld)',
          array(
            ArcanistDifferentialRevisionStatus::ACCEPTED,
          ));
        break;
      case self::STATUS_CLOSED:
        $where[] = qsprintf(
          $conn_r,
          'r.status IN (%Ld)',
          DifferentialRevisionStatus::getClosedStatuses());
        break;
      case self::STATUS_ABANDONED:
        $where[] = qsprintf(
          $conn_r,
          'r.status IN (%Ld)',
          array(
            ArcanistDifferentialRevisionStatus::ABANDONED,
          ));
        break;
      default:
        throw new Exception(
          "Unknown revision status filter constant '{$this->status}'!");
    }

    $where[] = $this->buildPagingClause($conn_r);
    return $this->formatWhereClause($where);
  }


  /**
   * @task internal
   */
  private function buildGroupByClause($conn_r) {
    $join_triggers = array_merge(
      $this->pathIDs,
      $this->ccs,
      $this->reviewers);

    $needs_distinct = (count($join_triggers) > 1);

    if ($needs_distinct) {
      return 'GROUP BY r.id';
    } else {
      return '';
    }
  }

  private function loadCursorObject($id) {
    $results = id(new DifferentialRevisionQuery())
      ->setViewer($this->getPagingViewer())
      ->withIDs(array((int)$id))
      ->execute();
    return head($results);
  }

  protected function buildPagingClause(AphrontDatabaseConnection $conn_r) {
    $default = parent::buildPagingClause($conn_r);

    $before_id = $this->getBeforeID();
    $after_id = $this->getAfterID();

    if (!$before_id && !$after_id) {
      return $default;
    }

    if ($before_id) {
      $cursor = $this->loadCursorObject($before_id);
    } else {
      $cursor = $this->loadCursorObject($after_id);
    }

    if (!$cursor) {
      return null;
    }

    $columns = array();

    switch ($this->order) {
      case self::ORDER_CREATED:
        return $default;
      case self::ORDER_MODIFIED:
        $columns[] = array(
          'name' => 'r.dateModified',
          'value' => $cursor->getDateModified(),
          'type' => 'int',
        );
        break;
      case self::ORDER_PATH_MODIFIED:
        $columns[] = array(
          'name' => 'p.epoch',
          'value' => $cursor->getDateCreated(),
          'type' => 'int',
        );
        break;
    }

    $columns[] = array(
      'name' => 'r.id',
      'value' => $cursor->getID(),
      'type' => 'int',
    );

    return $this->buildPagingClauseFromMultipleColumns(
      $conn_r,
      $columns,
      array(
        'reversed' => (bool)($before_id xor $this->getReversePaging()),
      ));
  }

  protected function getPagingColumn() {
    $is_global = $this->buildingGlobalOrder;
    switch ($this->order) {
      case self::ORDER_MODIFIED:
        if ($is_global) {
          return 'dateModified';
        }
        return 'r.dateModified';
      case self::ORDER_CREATED:
        if ($is_global) {
          return 'id';
        }
        return 'r.id';
      case self::ORDER_PATH_MODIFIED:
        if (!$this->pathIDs) {
          throw new Exception(
            "To use ORDER_PATH_MODIFIED, you must specify withPath().");
        }
        return 'p.epoch';
      default:
        throw new Exception("Unknown query order constant '{$this->order}'.");
    }
  }

  private function loadRelationships($conn_r, array $revisions) {
    assert_instances_of($revisions, 'DifferentialRevision');

    $type_reviewer = PhabricatorEdgeConfig::TYPE_DREV_HAS_REVIEWER;
    $type_subscriber = PhabricatorEdgeConfig::TYPE_OBJECT_HAS_SUBSCRIBER;

    $edges = id(new PhabricatorEdgeQuery())
      ->withSourcePHIDs(mpull($revisions, 'getPHID'))
      ->withEdgeTypes(array($type_reviewer, $type_subscriber))
      ->setOrder(PhabricatorEdgeQuery::ORDER_OLDEST_FIRST)
      ->execute();

    $type_map = array(
      DifferentialRevision::RELATION_REVIEWER => $type_reviewer,
      DifferentialRevision::RELATION_SUBSCRIBED => $type_subscriber,
    );

    foreach ($revisions as $revision) {
      $data = array();
      foreach ($type_map as $rel_type => $edge_type) {
        $revision_edges = $edges[$revision->getPHID()][$edge_type];
        foreach ($revision_edges as $dst_phid => $edge_data) {
          $data[] = array(
            'relation' => $rel_type,
            'objectPHID' => $dst_phid,
            'reasonPHID' => null,
          );
        }
      }

      $revision->attachRelationships($data);
    }
  }

  private function loadCommitPHIDs($conn_r, array $revisions) {
    assert_instances_of($revisions, 'DifferentialRevision');
    $commit_phids = queryfx_all(
      $conn_r,
      'SELECT * FROM %T WHERE revisionID IN (%Ld)',
      DifferentialRevision::TABLE_COMMIT,
      mpull($revisions, 'getID'));
    $commit_phids = igroup($commit_phids, 'revisionID');
    foreach ($revisions as $revision) {
      $phids = idx($commit_phids, $revision->getID(), array());
      $phids = ipull($phids, 'commitPHID');
      $revision->attachCommitPHIDs($phids);
    }
  }

  private function loadDiffIDs($conn_r, array $revisions) {
    assert_instances_of($revisions, 'DifferentialRevision');

    $diff_table = new DifferentialDiff();

    $diff_ids = queryfx_all(
      $conn_r,
      'SELECT revisionID, id FROM %T WHERE revisionID IN (%Ld)
        ORDER BY id DESC',
      $diff_table->getTableName(),
      mpull($revisions, 'getID'));
    $diff_ids = igroup($diff_ids, 'revisionID');

    foreach ($revisions as $revision) {
      $ids = idx($diff_ids, $revision->getID(), array());
      $ids = ipull($ids, 'id');
      $revision->attachDiffIDs($ids);
    }
  }

  private function loadActiveDiffs($conn_r, array $revisions) {
    assert_instances_of($revisions, 'DifferentialRevision');

    $diff_table = new DifferentialDiff();

    $load_ids = array();
    foreach ($revisions as $revision) {
      $diffs = $revision->getDiffIDs();
      if ($diffs) {
        $load_ids[] = max($diffs);
      }
    }

    $active_diffs = array();
    if ($load_ids) {
      $active_diffs = $diff_table->loadAllWhere(
        'id IN (%Ld)',
        $load_ids);
    }

    $active_diffs = mpull($active_diffs, null, 'getRevisionID');
    foreach ($revisions as $revision) {
      $revision->attachActiveDiff(idx($active_diffs, $revision->getID()));
    }
  }

  private function loadHashes(
    AphrontDatabaseConnection $conn_r,
    array $revisions) {
    assert_instances_of($revisions, 'DifferentialRevision');

    $data = queryfx_all(
      $conn_r,
      'SELECT * FROM %T WHERE revisionID IN (%Ld)',
      'differential_revisionhash',
      mpull($revisions, 'getID'));

    $data = igroup($data, 'revisionID');
    foreach ($revisions as $revision) {
      $hashes = idx($data, $revision->getID(), array());
      $list = array();
      foreach ($hashes as $hash) {
        $list[] = array($hash['type'], $hash['hash']);
      }
      $revision->attachHashes($list);
    }
  }

  private function loadReviewers(
    AphrontDatabaseConnection $conn_r,
    array $revisions) {

    assert_instances_of($revisions, 'DifferentialRevision');
    $edge_type = PhabricatorEdgeConfig::TYPE_DREV_HAS_REVIEWER;

    $edges = id(new PhabricatorEdgeQuery())
      ->withSourcePHIDs(mpull($revisions, 'getPHID'))
      ->withEdgeTypes(array($edge_type))
      ->needEdgeData(true)
      ->setOrder(PhabricatorEdgeQuery::ORDER_OLDEST_FIRST)
      ->execute();

    $viewer = $this->getViewer();
    $viewer_phid = $viewer->getPHID();
    $allow_key = 'differential.allow-self-accept';
    $allow_self = PhabricatorEnv::getEnvConfig($allow_key);

    // Figure out which of these reviewers the viewer has authority to act as.
    if ($this->needReviewerAuthority && $viewer_phid) {
      $authority = $this->loadReviewerAuthority(
        $revisions,
        $edges,
        $allow_self);
    }

    foreach ($revisions as $revision) {
      $revision_edges = $edges[$revision->getPHID()][$edge_type];
      $reviewers = array();
      foreach ($revision_edges as $reviewer_phid => $edge) {
        $reviewer = new DifferentialReviewer($reviewer_phid, $edge['data']);

        if ($this->needReviewerAuthority) {
          if (!$viewer_phid) {
            // Logged-out users never have authority.
            $has_authority = false;
          } else if ((!$allow_self) &&
                     ($revision->getAuthorPHID() == $viewer_phid)) {
            // The author can never have authority unless we allow self-accept.
            $has_authority = false;
          } else {
            // Otherwise, look up whether th viewer has authority.
            $has_authority = isset($authority[$reviewer_phid]);
          }

          $reviewer->attachAuthority($viewer, $has_authority);
        }

        $reviewers[$reviewer_phid] = $reviewer;
      }

      $revision->attachReviewerStatus($reviewers);
    }

  }


  public static function splitResponsible(array $revisions, array $user_phids) {
    $blocking = array();
    $active = array();
    $waiting = array();
    $status_review = ArcanistDifferentialRevisionStatus::NEEDS_REVIEW;

    // Bucket revisions into $blocking (revisions where you are blocking
    // others), $active (revisions you need to do something about) and $waiting
    // (revisions you're waiting on someone else to do something about).
    foreach ($revisions as $revision) {
      $needs_review = ($revision->getStatus() == $status_review);
      $filter_is_author = in_array($revision->getAuthorPHID(), $user_phids);
      if (!$revision->getReviewers()) {
        $needs_review = false;
        $author_is_reviewer = false;
      } else {
        $author_is_reviewer = in_array(
          $revision->getAuthorPHID(),
          $revision->getReviewers());
      }

      // If exactly one of "needs review" and "the user is the author" is
      // true, the user needs to act on it. Otherwise, they're waiting on
      // it.
      if ($needs_review ^ $filter_is_author) {
        if ($needs_review) {
          array_unshift($blocking, $revision);
        } else {
          $active[] = $revision;
        }
      // User is author **and** reviewer. An exotic but configurable workflow.
      // User needs to act on it double.
      } else if ($needs_review && $author_is_reviewer) {
        array_unshift($blocking, $revision);
        $active[] = $revision;
      } else {
        $waiting[] = $revision;
      }
    }

    return array($blocking, $active, $waiting);
  }

  private function loadReviewerAuthority(
    array $revisions,
    array $edges,
    $allow_self) {

    $revision_map = mpull($revisions, null, 'getPHID');
    $viewer_phid = $this->getViewer()->getPHID();

    // Find all the project reviewers which the user may have authority over.
    $project_phids = array();
    $project_type = PhabricatorProjectPHIDTypeProject::TYPECONST;
    $edge_type = PhabricatorEdgeConfig::TYPE_DREV_HAS_REVIEWER;
    foreach ($edges as $src => $types) {
      if (!$allow_self) {
        if ($revision_map[$src]->getAuthorPHID() == $viewer_phid) {
          // If self-review isn't permitted, the user will never have
          // authority over projects on revisions they authored because you
          // can't accept your own revisions, so we don't need to load any
          // data about these reviewers.
          continue;
        }
      }
      $edge_data = idx($types, $edge_type, array());
      foreach ($edge_data as $dst => $data) {
        if (phid_get_type($dst) == $project_type) {
          $project_phids[] = $dst;
        }
      }
    }

    // Now, figure out which of these projects the viewer is actually a
    // member of.
    $project_authority = array();
    if ($project_phids) {
      $project_authority = id(new PhabricatorProjectQuery())
        ->setViewer($this->getViewer())
        ->withPHIDs($project_phids)
        ->withMemberPHIDs(array($viewer_phid))
        ->execute();
      $project_authority = mpull($project_authority, 'getPHID');
    }

    // Finally, the viewer has authority over themselves.
    return array(
      $viewer_phid => true,
    ) + array_fuse($project_authority);
  }

  public function getQueryApplicationClass() {
    return 'PhabricatorApplicationDifferential';
  }

}
