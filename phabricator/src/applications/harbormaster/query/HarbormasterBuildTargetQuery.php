<?php

final class HarbormasterBuildTargetQuery
  extends PhabricatorCursorPagedPolicyAwareQuery {

  private $ids;
  private $phids;
  private $buildPHIDs;

  public function withIDs(array $ids) {
    $this->ids = $ids;
    return $this;
  }

  public function withPHIDs(array $phids) {
    $this->phids = $phids;
    return $this;
  }

  public function withBuildPHIDs(array $build_phids) {
    $this->buildPHIDs = $build_phids;
    return $this;
  }

  protected function loadPage() {
    $table = new HarbormasterBuildTarget();
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

  private function buildWhereClause(AphrontDatabaseConnection $conn_r) {
    $where = array();

    if ($this->ids) {
      $where[] = qsprintf(
        $conn_r,
        'id IN (%Ld)',
        $this->ids);
    }

    if ($this->phids) {
      $where[] = qsprintf(
        $conn_r,
        'phid in (%Ls)',
        $this->phids);
    }

    if ($this->buildPHIDs) {
      $where[] = qsprintf(
        $conn_r,
        'buildPHID in (%Ls)',
        $this->buildPHIDs);
    }

    $where[] = $this->buildPagingClause($conn_r);

    return $this->formatWhereClause($where);
  }

  protected function willFilterPage(array $page) {
    $builds = array();

    $build_phids = array_filter(mpull($page, 'getBuildPHID'));
    if ($build_phids) {
      $builds = id(new PhabricatorObjectQuery())
        ->setViewer($this->getViewer())
        ->withPHIDs($build_phids)
        ->setParentQuery($this)
        ->execute();
      $builds = mpull($builds, null, 'getPHID');
    }

    foreach ($page as $key => $build_target) {
      $build_phid = $build_target->getBuildPHID();
      if (empty($builds[$build_phid])) {
        unset($page[$key]);
        continue;
      }
      $build_target->attachBuild($builds[$build_phid]);
    }

    return $page;
  }

  public function getQueryApplicationClass() {
    return 'PhabricatorApplicationHarbormaster';
  }

}
