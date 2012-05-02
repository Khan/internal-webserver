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

final class HeraldCommitAdapter extends HeraldObjectAdapter {

  protected $diff;
  protected $revision;

  protected $repository;
  protected $commit;
  protected $commitData;

  protected $emailPHIDs = array();
  protected $auditMap = array();

  protected $affectedPaths;
  protected $affectedRevision;
  protected $affectedPackages;
  protected $auditNeededPackages;

  public function __construct(
    PhabricatorRepository $repository,
    PhabricatorRepositoryCommit $commit,
    PhabricatorRepositoryCommitData $commit_data) {

    $this->repository = $repository;
    $this->commit = $commit;
    $this->commitData = $commit_data;
  }

  public function getPHID() {
    return $this->commit->getPHID();
  }

  public function getEmailPHIDs() {
    return array_keys($this->emailPHIDs);
  }

  public function getAuditMap() {
    return $this->auditMap;
  }

  public function getHeraldName() {
    return
      'r'.
      $this->repository->getCallsign().
      $this->commit->getCommitIdentifier();
  }

  public function getHeraldTypeName() {
    return HeraldContentTypeConfig::CONTENT_TYPE_COMMIT;
  }

  public function loadAffectedPaths() {
    if ($this->affectedPaths === null) {
      $result = PhabricatorOwnerPathQuery::loadAffectedPaths(
        $this->repository, $this->commit);
      $this->affectedPaths = $result;
    }
    return $this->affectedPaths;
  }

  public function loadAffectedPackages() {
    if ($this->affectedPackages === null) {
      $packages = PhabricatorOwnersPackage::loadAffectedPackages(
        $this->repository,
        $this->loadAffectedPaths());
      $this->affectedPackages = $packages;
    }
    return $this->affectedPackages;
  }

  public function loadAuditNeededPackage() {
    if ($this->auditNeededPackages === null) {
      $status_arr = array(
        PhabricatorAuditStatusConstants::AUDIT_REQUIRED,
        PhabricatorAuditStatusConstants::CONCERNED,
      );
      $requests = id(new PhabricatorRepositoryAuditRequest())
          ->loadAllWhere(
        "commitPHID = %s AND auditStatus IN (%Ls)",
        $this->commit->getPHID(),
        $status_arr);

      $packages = mpull($requests, 'getAuditorPHID');
      $this->auditNeededPackages = $packages;
    }
    return $this->auditNeededPackages;
  }

  public function loadDifferentialRevision() {
    if ($this->affectedRevision === null) {
      $this->affectedRevision = false;
      $data = $this->commitData;
      $revision_id = $data->getCommitDetail('differential.revisionID');
      if ($revision_id) {
        $revision = id(new DifferentialRevision())->load($revision_id);
        if ($revision) {
          $revision->loadRelationships();
          $this->affectedRevision = $revision;
        }
      }
    }
    return $this->affectedRevision;
  }

  public function getHeraldField($field) {
    $data = $this->commitData;
    switch ($field) {
      case HeraldFieldConfig::FIELD_BODY:
        return $data->getCommitMessage();
      case HeraldFieldConfig::FIELD_AUTHOR:
        return $data->getCommitDetail('authorPHID');
      case HeraldFieldConfig::FIELD_REVIEWER:
        return $data->getCommitDetail('reviewerPHID');
      case HeraldFieldConfig::FIELD_DIFF_FILE:
        return $this->loadAffectedPaths();
      case HeraldFieldConfig::FIELD_REPOSITORY:
        return $this->repository->getPHID();
      case HeraldFieldConfig::FIELD_DIFF_CONTENT:
        // TODO!
        return null;
/*
        try {
          $diff = $this->loadDiff();
        } catch (Exception $ex) {
          // See rE280053 for an example.
          return array(
            '<<< Failed to load diff, this usually means the change committed '.
            'a binary file as text. >>>',
          );
        }
        $dict = array();
        $changes = $diff->getChangesets();
        $lines = array();
        foreach ($changes as $change) {
          $lines = array();
          foreach ($change->getHunks() as $hunk) {
            $lines[] = $hunk->makeChanges();
          }
          $dict[$change->getTrueFilename()] = implode("\n", $lines);
        }
        return $dict;
*/
      case HeraldFieldConfig::FIELD_AFFECTED_PACKAGE:
        $packages = $this->loadAffectedPackages();
        return mpull($packages, 'getPHID');
      case HeraldFieldConfig::FIELD_AFFECTED_PACKAGE_OWNER:
        $packages = $this->loadAffectedPackages();
        $owners = PhabricatorOwnersOwner::loadAllForPackages($packages);
        return mpull($owners, 'getUserPHID');
      case HeraldFieldConfig::FIELD_NEED_AUDIT_FOR_PACKAGE:
        return $this->loadAuditNeededPackage();
      case HeraldFieldConfig::FIELD_DIFFERENTIAL_REVISION:
        $revision = $this->loadDifferentialRevision();
        if (!$revision) {
          return null;
        }
        return $revision->getID();
      case HeraldFieldConfig::FIELD_DIFFERENTIAL_REVIEWERS:
        $revision = $this->loadDifferentialRevision();
        if (!$revision) {
          return null;
        }
        return $revision->getReviewers();
      case HeraldFieldConfig::FIELD_DIFFERENTIAL_CCS:
        $revision = $this->loadDifferentialRevision();
        if (!$revision) {
          return null;
        }
        return $revision->getCCPHIDs();
      default:
        throw new Exception("Invalid field '{$field}'.");
    }
  }

  public function applyHeraldEffects(array $effects) {
    assert_instances_of($effects, 'HeraldEffect');

    $result = array();
    foreach ($effects as $effect) {
      $action = $effect->getAction();
      switch ($action) {
        case HeraldActionConfig::ACTION_NOTHING:
          $result[] = new HeraldApplyTranscript(
            $effect,
            true,
            'Great success at doing nothing.');
          break;
        case HeraldActionConfig::ACTION_EMAIL:
          foreach ($effect->getTarget() as $phid) {
            $this->emailPHIDs[$phid] = true;
          }
          $result[] = new HeraldApplyTranscript(
            $effect,
            true,
            'Added address to email targets.');
          break;
        case HeraldActionConfig::ACTION_AUDIT:
          foreach ($effect->getTarget() as $phid) {
            if (empty($this->auditMap[$phid])) {
              $this->auditMap[$phid] = array();
            }
            $this->auditMap[$phid][] = $effect->getRuleID();
          }
          $result[] = new HeraldApplyTranscript(
            $effect,
            true,
            'Triggered an audit.');
          break;
        case HeraldActionConfig::ACTION_FLAG:
          $result[] = parent::applyFlagEffect(
            $effect,
            $this->commit->getPHID());
          break;
        default:
          throw new Exception("No rules to handle action '{$action}'.");
      }
    }
    return $result;
  }
}
