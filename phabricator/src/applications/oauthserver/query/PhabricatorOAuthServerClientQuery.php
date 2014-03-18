<?php

final class PhabricatorOAuthServerClientQuery
  extends PhabricatorCursorPagedPolicyAwareQuery {

  private $phids;
  private $creatorPHIDs;

  public function withPHIDs(array $phids) {
    $this->phids = $phids;
    return $this;
  }

  public function withCreatorPHIDs(array $phids) {
    $this->creatorPHIDs = $phids;
    return $this;
  }

  public function loadPage() {
    $table  = new PhabricatorOAuthServerClient();
    $conn_r = $table->establishConnection('r');


    $data = queryfx_all(
      $conn_r,
      'SELECT * FROM %T client %Q %Q %Q',
      $table->getTableName(),
      $this->buildWhereClause($conn_r),
      $this->buildOrderClause($conn_r),
      $this->buildLimitClause($conn_r));

    return $table->loadAllFromArray($data);
  }

  private function buildWhereClause($conn_r) {
    $where = array();

    if ($this->phids) {
      $where[] = qsprintf(
        $conn_r,
        'phid IN (%Ls)',
        $this->phids);
    }

    if ($this->creatorPHIDs) {
      $where[] = qsprintf(
        $conn_r,
        'creatorPHID IN (%Ls)',
        $this->creatorPHIDs);
    }

    $where[] = $this->buildPagingClause($conn_r);

    return $this->formatWhereClause($where);
  }

  public function getQueryApplicationClass() {
    return 'PhabricatorApplicationOAuthServer';
  }

}
