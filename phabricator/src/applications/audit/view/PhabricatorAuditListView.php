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

final class PhabricatorAuditListView extends AphrontView {

  private $audits;
  private $handles;
  private $authorityPHIDs = array();
  private $noDataString;
  private $commits;
  private $user;
  private $showDescriptions = true;

  public function setAudits(array $audits) {
    assert_instances_of($audits, 'PhabricatorRepositoryAuditRequest');
    $this->audits = $audits;
    return $this;
  }

  public function setHandles(array $handles) {
    assert_instances_of($handles, 'PhabricatorObjectHandle');
    $this->handles = $handles;
    return $this;
  }

  public function setAuthorityPHIDs(array $phids) {
    $this->authorityPHIDs = $phids;
    return $this;
  }

  public function setNoDataString($no_data_string) {
    $this->noDataString = $no_data_string;
    return $this;
  }

  public function getNoDataString() {
    return $this->noDataString;
  }

  public function setCommits(array $commits) {
    assert_instances_of($commits, 'PhabricatorRepositoryCommit');
    $this->commits = mpull($commits, null, 'getPHID');
    return $this;
  }

  public function setUser(PhabricatorUser $user) {
    $this->user = $user;
    return $this;
  }

  public function setShowDescriptions($show_descriptions) {
    $this->showDescriptions = $show_descriptions;
    return $this;
  }

  public function getRequiredHandlePHIDs() {
    $phids = array();
    foreach ($this->audits as $audit) {
      $phids[$audit->getCommitPHID()] = true;
      $phids[$audit->getAuditorPHID()] = true;
    }
    return array_keys($phids);
  }

  private function getHandle($phid) {
    $handle = idx($this->handles, $phid);
    if (!$handle) {
      throw new Exception("No handle for '{$phid}'!");
    }
    return $handle;
  }

  private function getCommitDescription($phid) {
    if ($this->commits === null) {
      return null;
    }

    $commit = idx($this->commits, $phid);
    if (!$commit) {
      return null;
    }

    return $commit->getCommitData()->getSummary();
  }

  public function render() {
    $user = $this->user;

    $authority = array_fill_keys($this->authorityPHIDs, true);

    $rowc = array();

    $last = null;
    $rows = array();
    foreach ($this->audits as $audit) {
      $commit_phid = $audit->getCommitPHID();
      if ($last == $commit_phid) {
        $commit_name = null;
        $commit_desc = null;
      } else {
        $commit_name = $this->getHandle($commit_phid)->renderLink();
        $commit_desc = $this->getCommitDescription($commit_phid);
        $last = $commit_phid;
      }

      $reasons = $audit->getAuditReasons();
      foreach ($reasons as $key => $reason) {
        $reasons[$key] = phutil_escape_html($reason);
      }
      $reasons = implode('<br />', $reasons);

      $status_code = $audit->getAuditStatus();
      $status = PhabricatorAuditStatusConstants::getStatusName($status_code);

      $auditor_handle = $this->getHandle($audit->getAuditorPHID());
      $rows[] = array(
        $commit_name,
        phutil_escape_html($commit_desc),
        $auditor_handle->renderLink(),
        phutil_escape_html($status),
        $reasons,
      );

      $row_class = null;

      $has_authority = !empty($authority[$audit->getAuditorPHID()]);
      if ($has_authority) {
        $commit_author = $this->commits[$commit_phid]->getAuthorPHID();

        // You don't have authority over package and project audits on your own
        // commits.

        $auditor_is_user = ($audit->getAuditorPHID() == $user->getPHID());
        $user_is_author = ($commit_author == $user->getPHID());

        if ($auditor_is_user || !$user_is_author) {
          $row_class = 'highlighted';
        }
      }

      $rowc[] = $row_class;
    }

    $table = new AphrontTableView($rows);
    $table->setHeaders(
      array(
        'Commit',
        'Description',
        'Auditor',
        'Status',
        'Details',
      ));
    $table->setColumnClasses(
      array(
        'pri',
        ($this->showDescriptions ? 'wide' : ''),
        '',
        '',
        ($this->showDescriptions ? '' : 'wide'),
      ));
    $table->setRowClasses($rowc);
    $table->setColumnVisibility(
      array(
        $this->showDescriptions,
        $this->showDescriptions,
        true,
        true,
        true,
      ));

    if ($this->noDataString) {
      $table->setNoDataString($this->noDataString);
    }

    return $table->render();
  }

}
