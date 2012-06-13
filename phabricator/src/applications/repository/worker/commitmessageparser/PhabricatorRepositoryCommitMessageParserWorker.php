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

abstract class PhabricatorRepositoryCommitMessageParserWorker
  extends PhabricatorRepositoryCommitParserWorker {

  abstract protected function getCommitHashes(
    PhabricatorRepository $repository,
    PhabricatorRepositoryCommit $commit);

  final protected function updateCommitData($author, $message,
    $committer = null) {

    $commit = $this->commit;

    $data = id(new PhabricatorRepositoryCommitData())->loadOneWhere(
      'commitID = %d',
      $commit->getID());
    if (!$data) {
      $data = new PhabricatorRepositoryCommitData();
    }
    $data->setCommitID($commit->getID());
    $data->setAuthorName($author);
    $data->setCommitMessage($message);

    if ($committer) {
      $data->setCommitDetail('committer', $committer);
    }

    $repository = $this->repository;
    $detail_parser = $repository->getDetail(
      'detail-parser',
      'PhabricatorRepositoryDefaultCommitMessageDetailParser');

    if ($detail_parser) {
      $parser_obj = newv($detail_parser, array($commit, $data));
      $parser_obj->parseCommitDetails();
    }

    $author_phid = $data->getCommitDetail('authorPHID');
    if ($author_phid) {
      $commit->setAuthorPHID($author_phid);
      $commit->save();
    }

    $conn_w = id(new DifferentialRevision())->establishConnection('w');

    // NOTE: The `differential_commit` table has a unique ID on `commitPHID`,
    // preventing more than one revision from being associated with a commit.
    // Generally this is good and desirable, but with the advent of hash
    // tracking we may end up in a situation where we match several different
    // revisions. We just kind of ignore this and pick one, we might want to
    // revisit this and do something differently. (If we match several revisions
    // someone probably did something very silly, though.)

    $revision_id = $data->getCommitDetail('differential.revisionID');
    if (!$revision_id) {
      $hashes = $this->getCommitHashes(
        $this->repository,
        $this->commit);
      if ($hashes) {

        $query = new DifferentialRevisionQuery();
        $query->withCommitHashes($hashes);
        $revisions = $query->execute();

        if (!empty($revisions)) {
          $revision = $this->identifyBestRevision($revisions);
          $revision_id = $revision->getID();
        }
      }
    }

    if ($revision_id) {
      $revision = id(new DifferentialRevision())->load($revision_id);
      if ($revision) {
        queryfx(
          $conn_w,
          'INSERT IGNORE INTO %T (revisionID, commitPHID) VALUES (%d, %s)',
          DifferentialRevision::TABLE_COMMIT,
          $revision->getID(),
          $commit->getPHID());
        $commit_is_new = $conn_w->getAffectedRows();

        $message = null;
        $name = $data->getCommitDetail('committer');
        if ($name !== null) {
          $committer = $data->getCommitDetail('committerPHID');
        } else {
          $committer = $data->getCommitDetail('authorPHID');
          $name = $data->getAuthorName();
        }
        if (!$committer) {
          $committer = $revision->getAuthorPHID();
          $message = 'Closed by '.$name.'.';
        }

        if ($commit_is_new) {
          $diff = $this->attachToRevision($revision, $committer);
        }

        $status_closed = ArcanistDifferentialRevisionStatus::CLOSED;
        $should_close = ($revision->getStatus() != $status_closed) &&
                        (!$repository->getDetail('disable-autoclose', false));

        if ($should_close) {
          $revision->setDateCommitted($commit->getEpoch());
          $editor = new DifferentialCommentEditor(
            $revision,
            $committer,
            DifferentialAction::ACTION_CLOSE);
          $editor->setIsDaemonWorkflow(true);

          if ($commit_is_new) {
            $vs_diff = $this->loadChangedByCommit($diff);
            if ($vs_diff) {
              $data->setCommitDetail('vsDiff', $vs_diff->getID());

              $changed_by_commit = PhabricatorEnv::getProductionURI(
                '/D'.$revision->getID().
                '?vs='.$vs_diff->getID().
                '&id='.$diff->getID().
                '#differential-review-toc');
              $editor->setChangedByCommit($changed_by_commit);
            }
          }

          $editor->setMessage($message)->save();
        }

      }
    }

    $data->save();
  }

  private function attachToRevision(
    DifferentialRevision $revision,
    $committer) {

    $drequest = DiffusionRequest::newFromDictionary(array(
      'repository' => $this->repository,
      'commit' => $this->commit->getCommitIdentifier(),
    ));

    $raw_diff = DiffusionRawDiffQuery::newFromDiffusionRequest($drequest)
      ->loadRawDiff();

    $changes = id(new ArcanistDiffParser())->parseDiff($raw_diff);
    $diff = DifferentialDiff::newFromRawChanges($changes)
      ->setRevisionID($revision->getID())
      ->setAuthorPHID($committer)
      ->setCreationMethod('commit')
      ->setSourceControlSystem($this->repository->getVersionControlSystem())
      ->setLintStatus(DifferentialLintStatus::LINT_SKIP)
      ->setUnitStatus(DifferentialUnitStatus::UNIT_SKIP)
      ->setDateCreated($this->commit->getEpoch())
      ->setDescription(
        'Commit r'.
        $this->repository->getCallsign().
        $this->commit->getCommitIdentifier());

    // TODO: This is not correct in SVN where one repository can have multiple
    // Arcanist projects.
    $arcanist_project = id(new PhabricatorRepositoryArcanistProject())
      ->loadOneWhere('repositoryID = %d LIMIT 1', $this->repository->getID());
    if ($arcanist_project) {
      $diff->setArcanistProjectPHID($arcanist_project->getPHID());
    }

    $parents = DiffusionCommitParentsQuery::newFromDiffusionRequest($drequest)
      ->loadParents();
    if ($parents) {
      $diff->setSourceControlBaseRevision(head_key($parents));
    }

    // TODO: Attach binary files.

    return $diff->save();
  }

  private function loadChangedByCommit(DifferentialDiff $diff) {
    $repository = $this->repository;

    $vs_changesets = array();
    $vs_diff = id(new DifferentialDiff())->loadOneWhere(
      'revisionID = %d AND creationMethod != %s ORDER BY id DESC LIMIT 1',
      $diff->getRevisionID(),
      'commit');
    foreach ($vs_diff->loadChangesets() as $changeset) {
      $path = $changeset->getAbsoluteRepositoryPath($repository, $vs_diff);
      $vs_changesets[$path] = $changeset;
    }

    $changesets = array();
    foreach ($diff->getChangesets() as $changeset) {
      $path = $changeset->getAbsoluteRepositoryPath($repository, $diff);
      $changesets[$path] = $changeset;
    }

    if (array_fill_keys(array_keys($changesets), true) !=
        array_fill_keys(array_keys($vs_changesets), true)) {
      return $vs_diff;
    }

    $hunks = id(new DifferentialHunk())->loadAllWhere(
      'changesetID IN (%Ld)',
      mpull($vs_changesets, 'getID'));
    $hunks = mgroup($hunks, 'getChangesetID');
    foreach ($vs_changesets as $changeset) {
      $changeset->attachHunks(idx($hunks, $changeset->getID(), array()));
    }

    $file_phids = array();
    foreach ($vs_changesets as $changeset) {
      $metadata = $changeset->getMetadata();
      $file_phid = idx($metadata, 'new:binary-phid');
      if ($file_phid) {
        $file_phids[$file_phid] = $file_phid;
      }
    }

    $files = array();
    if ($file_phids) {
      $files = id(new PhabricatorFile())->loadAllWhere(
        'phid IN (%Ls)',
        $file_phids);
      $files = mpull($files, null, 'getPHID');
    }

    foreach ($changesets as $path => $changeset) {
      $vs_changeset = $vs_changesets[$path];

      $file_phid = idx($vs_changeset->getMetadata(), 'new:binary-phid');
      if ($file_phid) {
        if (!isset($files[$file_phid])) {
          return $vs_diff;
        }
        $drequest = DiffusionRequest::newFromDictionary(array(
          'repository' => $this->repository,
          'commit' => $this->commit->getCommitIdentifier(),
          'path' => $path,
        ));
        $corpus = DiffusionFileContentQuery::newFromDiffusionRequest($drequest)
          ->loadFileContent()
          ->getCorpus();
        if ($files[$file_phid]->loadFileData() != $corpus) {
          return $vs_diff;
        }
      } else if ($changeset->makeChangesWithContext() !=
          $vs_changeset->makeChangesWithContext()) {
        return $vs_diff;
      }
    }

    return null;
  }

  /**
   * When querying for revisions by hash, more than one revision may be found.
   * This function identifies the "best" revision from such a set.  Typically,
   * there is only one revision found.   Otherwise, we try to pick an accepted
   * revision first, followed by an open revision, and otherwise we go with a
   * closed or abandoned revision as a last resort.
   */
  private function identifyBestRevision(array $revisions) {
    assert_instances_of($revisions, 'DifferentialRevision');
    // get the simplest, common case out of the way
    if (count($revisions) == 1) {
      return reset($revisions);
    }

    $first_choice = array();
    $second_choice = array();
    $third_choice = array();
    foreach ($revisions as $revision) {
      switch ($revision->getStatus()) {
        // "Accepted" revisions -- ostensibly what we're looking for!
        case ArcanistDifferentialRevisionStatus::ACCEPTED:
          $first_choice[] = $revision;
          break;
        // "Open" revisions
        case ArcanistDifferentialRevisionStatus::NEEDS_REVIEW:
        case ArcanistDifferentialRevisionStatus::NEEDS_REVISION:
          $second_choice[] = $revision;
          break;
        // default is a wtf? here
        default:
        case ArcanistDifferentialRevisionStatus::ABANDONED:
        case ArcanistDifferentialRevisionStatus::CLOSED:
          $third_choice[] = $revision;
          break;
      }
    }

    // go down the ladder like a bro at last call
    if (!empty($first_choice)) {
      return $this->identifyMostRecentRevision($first_choice);
    }
    if (!empty($second_choice)) {
      return $this->identifyMostRecentRevision($second_choice);
    }
    if (!empty($third_choice)) {
      return $this->identifyMostRecentRevision($third_choice);
    }
  }

  /**
   * Given a set of revisions, returns the revision with the latest
   * updated time.   This is ostensibly the most recent revision.
   */
  private function identifyMostRecentRevision(array $revisions) {
    assert_instances_of($revisions, 'DifferentialRevision');
    $revisions = msort($revisions, 'getDateModified');
    return end($revisions);
  }
}
