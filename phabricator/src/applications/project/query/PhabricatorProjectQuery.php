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

final class PhabricatorProjectQuery extends PhabricatorOffsetPagedQuery {

  private $ids;
  private $phids;
  private $memberPHIDs;

  private $status       = 'status-any';
  const STATUS_ANY      = 'status-any';
  const STATUS_OPEN     = 'status-open';
  const STATUS_CLOSED   = 'status-closed';
  const STATUS_ACTIVE   = 'status-active';
  const STATUS_ARCHIVED = 'status-archived';

  private $needMembers;

  public function withIDs(array $ids) {
    $this->ids = $ids;
    return $this;
  }

  public function withPHIDs(array $phids) {
    $this->phids = $phids;
    return $this;
  }

  public function withStatus($status) {
    $this->status = $status;
    return $this;
  }

  public function withMemberPHIDs(array $member_phids) {
    $this->memberPHIDs = $member_phids;
    return $this;
  }

  public function needMembers($need_members) {
    $this->needMembers = $need_members;
    return $this;
  }

  public function execute() {
    $table = new PhabricatorProject();
    $conn_r = $table->establishConnection('r');

    $where = $this->buildWhereClause($conn_r);
    $joins = $this->buildJoinsClause($conn_r);
    $order = 'ORDER BY name';

    $data = queryfx_all(
      $conn_r,
      'SELECT p.* FROM %T p %Q %Q %Q %Q',
      $table->getTableName(),
      $joins,
      $where,
      $order,
      $this->buildLimitClause($conn_r));

    $projects = $table->loadAllFromArray($data);

    if ($projects && $this->needMembers) {
      $members = PhabricatorProjectAffiliation::loadAllForProjectPHIDs(
        mpull($projects, 'getPHID'));
      foreach ($projects as $project) {
        $project->attachAffiliations(
          array_values(idx($members, $project->getPHID(), array())));
      }
    }

    return $projects;
  }

  private function buildWhereClause($conn_r) {
    $where = array();

    if ($this->status != self::STATUS_ANY) {
      switch ($this->status) {
        case self::STATUS_OPEN:
          $where[] = qsprintf(
            $conn_r,
            'status IN (%Ld)',
            array(
              PhabricatorProjectStatus::STATUS_ACTIVE,
            ));
          break;
        case self::STATUS_CLOSED:
          $where[] = qsprintf(
            $conn_r,
            'status IN (%Ld)',
            array(
              PhabricatorProjectStatus::STATUS_ARCHIVED,
            ));
          break;
        case self::STATUS_ACTIVE:
          $where[] = qsprintf(
            $conn_r,
            'status = %d',
            PhabricatorProjectStatus::STATUS_ACTIVE);
          break;
        case self::STATUS_ARCHIVED:
          $where[] = qsprintf(
            $conn_r,
            'status = %d',
            PhabricatorProjectStatus::STATUS_ARCHIVED);
          break;
        default:
          throw new Exception(
            "Unknown project status '{$this->status}'!");
      }
    }

    if ($this->ids) {
      $where[] = qsprintf(
        $conn_r,
        'id IN (%Ld)',
        $this->ids);
    }

    if ($this->phids) {
      $where[] = qsprintf(
        $conn_r,
        'phid IN (%Ls)',
        $this->phids);
    }

    return $this->formatWhereClause($where);
  }

  private function buildJoinsClause($conn_r) {
    $affil_table = new PhabricatorProjectAffiliation();

    $joins = array();
    if ($this->memberPHIDs) {
      $joins[] = qsprintf(
        $conn_r,
        'JOIN %T member ON member.projectPHID = p.phid
          AND member.userPHID in (%Ls)',
        $affil_table->getTableName(),
        $this->memberPHIDs);
    }

    return implode(' ', $joins);
  }

}
