<?php

final class DiffusionCommitQuery
  extends PhabricatorCursorPagedPolicyAwareQuery {

  private $identifiers;

  /**
   * Load commits by partial or full identifiers, e.g. "rXab82393", "rX1234",
   * or "a9caf12". When an identifier matches multiple commits, they will all
   * be returned; callers should be prepared to deal with more results than
   * they queried for.
   */
  public function withIdentifiers(array $identifiers) {
    $this->identifiers = $identifiers;
    return $this;
  }

  public function loadPage() {
    $table = new PhabricatorRepositoryCommit();
    $conn_r = $table->establishConnection('r');

    $data = queryfx_all(
      $conn_r,
      'SELECT * FROM %T %Q %Q %Q',
      $table->getTableName(),
      $this->buildWhereClause($conn_r),
      $this->buildOrderClause($conn_r),
      $this->buildLimitClause($conn_r));

    return $table->loadAllFromArray($data);
  }

  public function willFilterPage(array $commits) {
    if (!$commits) {
      return array();
    }

    $repository_ids = mpull($commits, 'getRepositoryID', 'getRepositoryID');
    $repos = id(new PhabricatorRepositoryQuery())
      ->setViewer($this->getViewer())
      ->withIDs($repository_ids)
      ->execute();

    foreach ($commits as $key => $commit) {
      $repo = idx($repos, $commit->getRepositoryID());
      if ($repo) {
        $commit->attachRepository($repo);
      } else {
        unset($commits[$key]);
      }
    }

    return $commits;
  }

  private function buildWhereClause(AphrontDatabaseConnection $conn_r) {
    $where = array();

    if ($this->identifiers) {
      $min_unqualified = PhabricatorRepository::MINIMUM_UNQUALIFIED_HASH;
      $min_qualified   = PhabricatorRepository::MINIMUM_QUALIFIED_HASH;

      $refs = array();
      $bare = array();
      foreach ($this->identifiers as $identifier) {
        $matches = null;
        preg_match('/^(?:r([A-Z]+))?(.*)$/', $identifier, $matches);
        $repo = nonempty($matches[1], null);
        $identifier = nonempty($matches[2], null);

        if ($repo === null) {
          if (strlen($identifier) < $min_unqualified) {
            continue;
          }
          $bare[] = $identifier;
        } else {
          $refs[] = array(
            'callsign' => $repo,
            'identifier' => $identifier,
          );
        }
      }

      $sql = array();

      foreach ($bare as $identifier) {
        $sql[] = qsprintf(
          $conn_r,
          '(commitIdentifier LIKE %> AND LENGTH(commitIdentifier) = 40)',
          $identifier);
      }

      if ($refs) {
        $callsigns = ipull($refs, 'callsign');
        $repos = id(new PhabricatorRepositoryQuery())
          ->setViewer($this->getViewer())
          ->withCallsigns($callsigns)
          ->execute();
        $repos = mpull($repos, null, 'getCallsign');

        foreach ($refs as $key => $ref) {
          $repo = idx($repos, $ref['callsign']);
          if (!$repo) {
            continue;
          }

          if ($repo->isSVN()) {
            $sql[] = qsprintf(
              $conn_r,
              '(repositoryID = %d AND commitIdentifier = %d)',
              $repo->getID(),
              $ref['identifier']);
          } else {
            if (strlen($ref['identifier']) < $min_qualified) {
              continue;
            }
            $sql[] = qsprintf(
              $conn_r,
              '(repositoryID = %d AND commitIdentifier LIKE %>)',
              $repo->getID(),
              $ref['identifier']);
          }
        }
      }

      if ($sql) {
        $where[] = '('.implode(' OR ', $sql).')';
      } else {
        // If we discarded all possible identifiers (e.g., they all referenced
        // bogus repositories or were all too short), make sure the query finds
        // nothing.
        $where[] = qsprintf($conn_r, '1 = 0');
      }
    }

    return $this->formatWhereClause($where);
  }

}
