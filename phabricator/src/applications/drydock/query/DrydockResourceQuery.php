<?php

final class DrydockResourceQuery
  extends PhabricatorCursorPagedPolicyAwareQuery {

  private $ids;
  private $statuses;
  private $types;

  public function withIDs(array $ids) {
    $this->ids = $ids;
    return $this;
  }

  public function withTypes(array $types) {
    $this->types = $types;
    return $this;
  }

  public function withStatuses(array $statuses) {
    $this->statuses = $statuses;
    return $this;
  }

  public function loadPage() {
    $table = new DrydockResource();
    $conn_r = $table->establishConnection('r');

    $data = queryfx_all(
      $conn_r,
      'SELECT resource.* FROM %T resource %Q %Q %Q',
      $table->getTableName(),
      $this->buildWhereClause($conn_r),
      $this->buildOrderClause($conn_r),
      $this->buildLimitClause($conn_r));

    $resources = $table->loadAllFromArray($data);

    return $resources;
  }

  private function buildWhereClause(AphrontDatabaseConnection $conn_r) {
    $where = array();

    if ($this->ids) {
      $where[] = qsprintf(
        $conn_r,
        'id IN (%Ld)',
        $this->ids);
    }

    if ($this->types) {
      $where[] = qsprintf(
        $conn_r,
        'type IN (%Ls)',
        $this->types);
    }

    if ($this->statuses) {
      $where[] = qsprintf(
        $conn_r,
        'status IN (%Ls)',
        $this->statuses);
    }

    $where[] = $this->buildPagingClause($conn_r);

    return $this->formatWhereClause($where);
  }

  public function getQueryApplicationClass() {
    return 'PhabricatorApplicationDrydock';
  }

}
