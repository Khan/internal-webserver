<?php

final class PhortuneAccountQuery
  extends PhabricatorCursorPagedPolicyAwareQuery {

  private $ids;
  private $phids;
  private $memberPHIDs;

  public function withIDs(array $ids) {
    $this->ids = $ids;
    return $this;
  }

  public function withPHIDs(array $phids) {
    $this->phids = $phids;
    return $this;
  }

  public function withMemberPHIDs(array $phids) {
    $this->memberPHIDs = $phids;
    return $this;
  }

  protected function loadPage() {
    $table = new PhortuneAccount();
    $conn = $table->establishConnection('r');

    $rows = queryfx_all(
      $conn,
      'SELECT a.* FROM %T a %Q %Q %Q %Q',
      $table->getTableName(),
      $this->buildJoinClause($conn),
      $this->buildWhereClause($conn),
      $this->buildOrderClause($conn),
      $this->buildLimitClause($conn));

    return $table->loadAllFromArray($rows);
  }

  protected function willFilterPage(array $accounts) {
    if (!$accounts) {
      return array();
    }

    $query = id(new PhabricatorEdgeQuery())
      ->withSourcePHIDs(mpull($accounts, 'getPHID'))
      ->withEdgeTypes(array(PhabricatorEdgeConfig::TYPE_ACCOUNT_HAS_MEMBER));
    $query->execute();

    foreach ($accounts as $account) {
      $member_phids = $query->getDestinationPHIDs(array($account->getPHID()));
      $account->attachMemberPHIDs($member_phids);
    }

    return $accounts;
  }

  private function buildWhereClause(AphrontDatabaseConnection $conn) {
    $where = array();

    $where[] = $this->buildPagingClause($conn);

    if ($this->ids) {
      $where[] = qsprintf(
        $conn,
        'a.id IN (%Ld)',
        $this->ids);
    }

    if ($this->phids) {
      $where[] = qsprintf(
        $conn,
        'a.phid IN (%Ls)',
        $this->phids);
    }

    if ($this->memberPHIDs) {
      $where[] = qsprintf(
        $conn,
        'm.dst IN (%Ls)',
        $this->memberPHIDs);
    }

    return $this->formatWhereClause($where);
  }

  private function buildJoinClause(AphrontDatabaseConnection $conn) {
    $joins = array();

    if ($this->memberPHIDs) {
      $joins[] = qsprintf(
        $conn,
        'LEFT JOIN %T m ON a.phid = m.src AND m.type = %d',
        PhabricatorEdgeConfig::TABLE_NAME_EDGE,
        PhabricatorEdgeConfig::TYPE_ACCOUNT_HAS_MEMBER);
    }

    return implode(' ', $joins);
  }

}
