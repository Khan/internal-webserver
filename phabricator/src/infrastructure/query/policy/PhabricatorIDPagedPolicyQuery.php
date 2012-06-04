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

/**
 * A query class which uses ID-based paging. This paging is much more performant
 * than offset-based paging in the presence of policy filtering.
 */
abstract class PhabricatorIDPagedPolicyQuery extends PhabricatorPolicyQuery {

  private $afterID;
  private $beforeID;

  protected function getPagingColumn() {
    return 'id';
  }

  protected function getPagingValue($result) {
    return $result->getID();
  }

  protected function nextPage(array $page) {
    if ($this->beforeID) {
      $this->beforeID = $this->getPagingValue(head($page));
    } else {
      $this->afterID = $this->getPagingValue(last($page));
    }
  }

  final public function setAfterID($object_id) {
    $this->afterID = $object_id;
    return $this;
  }

  final public function setBeforeID($object_id) {
    $this->beforeID = $object_id;
    return $this;
  }

  final protected function buildLimitClause(AphrontDatabaseConnection $conn_r) {
    if ($this->getLimit()) {
      return qsprintf($conn_r, 'LIMIT %d', $this->getLimit());
    } else {
      return '';
    }
  }

  final protected function buildPagingClause(
    AphrontDatabaseConnection $conn_r) {

    if ($this->beforeID) {
      return qsprintf(
        $conn_r,
        '%C > %s',
        $this->getPagingColumn(),
        $this->beforeID);
    } else if ($this->afterID) {
      return qsprintf(
        $conn_r,
        '%C < %s',
        $this->getPagingColumn(),
        $this->afterID);
    }

    return null;
  }

  final protected function buildOrderClause(AphrontDatabaseConnection $conn_r) {
    if ($this->beforeID) {
      return qsprintf(
        $conn_r,
        'ORDER BY %C ASC',
        $this->getPagingColumn());
    } else {
      return qsprintf(
        $conn_r,
        'ORDER BY %C DESC',
        $this->getPagingColumn());
    }
  }

  final protected function processResults(array $results) {
    if ($this->beforeID) {
      $results = array_reverse($results, $preserve_keys = true);
    }
    return $results;
  }


  final public function executeWithPager(AphrontIDPagerView $pager) {
    $this->setLimit($pager->getPageSize() + 1);

    if ($pager->getAfterID()) {
      $this->setAfterID($pager->getAfterID());
    } else if ($pager->getBeforeID()) {
      $this->setBeforeID($pager->getBeforeID());
    }

    $results = $this->execute();

    $sliced_results = $pager->sliceResults($results);

    if ($this->beforeID || (count($results) > $pager->getPageSize())) {
      $pager->setNextPageID($this->getPagingValue(last($sliced_results)));
    }

    if ($this->afterID ||
       ($this->beforeID && (count($results) > $pager->getPageSize()))) {
      $pager->setPrevPageID($this->getPagingValue(head($sliced_results)));
    }

    return $sliced_results;
  }

}
