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

final class DifferentialDiff extends DifferentialDAO {

  protected $revisionID;
  protected $authorPHID;

  protected $sourceMachine;
  protected $sourcePath;

  protected $sourceControlSystem;
  protected $sourceControlBaseRevision;
  protected $sourceControlPath;

  protected $lintStatus;
  protected $unitStatus;

  protected $lineCount;

  protected $branch;

  protected $parentRevisionID;
  protected $arcanistProjectPHID;
  protected $creationMethod;
  protected $repositoryUUID;

  protected $description;

  private $unsavedChangesets = array();
  private $changesets;

  public function addUnsavedChangeset(DifferentialChangeset $changeset) {
    if ($this->changesets === null) {
      $this->changesets = array();
    }
    $this->unsavedChangesets[] = $changeset;
    $this->changesets[] = $changeset;
    return $this;
  }

  public function attachChangesets(array $changesets) {
    assert_instances_of($changesets, 'DifferentialChangeset');
    $this->changesets = $changesets;
    return $this;
  }

  public function getChangesets() {
    if ($this->changesets === null) {
      throw new Exception("Must load and attach changesets first!");
    }
    return $this->changesets;
  }

  public function loadChangesets() {
    if (!$this->getID()) {
      return array();
    }
    return id(new DifferentialChangeset())->loadAllWhere(
      'diffID = %d',
      $this->getID());
  }

  public function loadArcanistProject() {
    if (!$this->getArcanistProjectPHID()) {
      return null;
    }
    return id(new PhabricatorRepositoryArcanistProject())->loadOneWhere(
      'phid = %s',
      $this->getArcanistProjectPHID());
  }

  public function getBackingVersionControlSystem() {
    $arcanist_project = $this->loadArcanistProject();
    if (!$arcanist_project) {
      return null;
    }
    $repository = $arcanist_project->loadRepository();
    if (!$repository) {
      return null;
    }
    return $repository->getVersionControlSystem();
  }

  public function save() {
// TODO: sort out transactions
//    $this->openTransaction();
      $ret = parent::save();
      foreach ($this->unsavedChangesets as $changeset) {
        $changeset->setDiffID($this->getID());
        $changeset->save();
      }
//    $this->saveTransaction();
    return $ret;
  }

  public function delete() {
//    $this->openTransaction();
      foreach ($this->loadChangesets() as $changeset) {
        $changeset->delete();
      }
      $ret = parent::delete();
//    $this->saveTransaction();
    return $ret;
  }

  public static function newFromRawChanges(array $changes) {
    assert_instances_of($changes, 'ArcanistDiffChange');
    $diff = new DifferentialDiff();

    $lines = 0;
    foreach ($changes as $change) {
      $changeset = new DifferentialChangeset();
      $add_lines = 0;
      $del_lines = 0;
      $hunks = $change->getHunks();
      if ($hunks) {
        foreach ($hunks as $hunk) {
          $dhunk = new DifferentialHunk();
          $dhunk->setOldOffset($hunk->getOldOffset());
          $dhunk->setOldLen($hunk->getOldLength());
          $dhunk->setNewOffset($hunk->getNewOffset());
          $dhunk->setNewLen($hunk->getNewLength());
          $dhunk->setChanges($hunk->getCorpus());
          $changeset->addUnsavedHunk($dhunk);
          $add_lines += $hunk->getAddLines();
          $del_lines += $hunk->getDelLines();
          $lines += $add_lines + $del_lines;
        }
      } else {
        // This happens when you add empty files.
        $changeset->attachHunks(array());
      }

      $changeset->setOldFile($change->getOldPath());
      $changeset->setFilename($change->getCurrentPath());
      $changeset->setChangeType($change->getType());

      $changeset->setFileType($change->getFileType());
      $changeset->setMetadata($change->getAllMetadata());
      $changeset->setOldProperties($change->getOldProperties());
      $changeset->setNewProperties($change->getNewProperties());
      $changeset->setAwayPaths($change->getAwayPaths());
      $changeset->setAddLines($add_lines);
      $changeset->setDelLines($del_lines);

      $diff->addUnsavedChangeset($changeset);
    }
    $diff->setLineCount($lines);

    $diff->detectCopiedCode();

    return $diff;
  }

  public function detectCopiedCode($min_width = 30, $min_lines = 3) {
    $map = array();
    $files = array();
    $types = array();
    foreach ($this->changesets as $changeset) {
      $file = $changeset->getFilename();
      foreach ($changeset->getHunks() as $hunk) {
        $line = $hunk->getOldOffset();
        foreach (explode("\n", $hunk->getChanges()) as $code) {
          $type = (isset($code[0]) ? $code[0] : '');
          if ($type == '-' || $type == ' ') {
            $code = trim(substr($code, 1));
            $files[$file][$line] = $code;
            $types[$file][$line] = $type;
            if (strlen($code) >= $min_width) {
              $map[$code][] = array($file, $line);
            }
            $line++;
          }
        }
      }
    }

    foreach ($this->changesets as $changeset) {
      $copies = array();
      foreach ($changeset->getHunks() as $hunk) {
        $added = array_map('trim', $hunk->getAddedLines());
        for (reset($added); list($line, $code) = each($added); next($added)) {
          if (isset($map[$code])) { // We found a long matching line.
            $best_length = 0;
            foreach ($map[$code] as $val) { // Explore all candidates.
              list($file, $orig_line) = $val;
              $length = 1;
              // Search also backwards for short lines.
              foreach (array(-1, 1) as $direction) {
                $offset = $direction;
                while (!isset($copies[$line + $offset]) &&
                    isset($added[$line + $offset]) &&
                    idx($files[$file], $orig_line + $offset) ===
                      $added[$line + $offset]) {
                  $length++;
                  $offset += $direction;
                }
              }
              if ($length > $best_length ||
                  ($length == $best_length && // Prefer moves.
                   idx($types[$file], $orig_line) == '-')) {
                $best_length = $length;
                // ($offset - 1) contains number of forward matching lines.
                $best_offset = $offset - 1;
                $best_file = $file;
                $best_line = $orig_line;
              }
            }
            $file = ($best_file == $changeset->getFilename() ? '' : $best_file);
            for ($i = $best_length; $i--; ) {
              $type = idx($types[$best_file], $best_line + $best_offset - $i);
              $copies[$line + $best_offset - $i] = ($best_length < $min_lines
                ? array() // Ignore short blocks.
                : array($file, $best_line + $best_offset - $i, $type));
            }
            for ($i = 0; $i < $best_offset; $i++) {
              next($added);
            }
          }
        }
      }
      $metadata = $changeset->getMetadata();
      $metadata['copy:lines'] = array_filter($copies);
      $changeset->setMetadata($metadata);
    }
  }

  public function getDiffDict() {
    $dict = array(
      'id' => $this->getID(),
      'parent' => $this->getParentRevisionID(),
      'revisionID' => $this->getRevisionID(),
      'sourceControlBaseRevision' => $this->getSourceControlBaseRevision(),
      'sourceControlPath' => $this->getSourceControlPath(),
      'sourceControlSystem' => $this->getSourceControlSystem(),
      'branch' => $this->getBranch(),
      'unitStatus' => $this->getUnitStatus(),
      'lintStatus' => $this->getLintStatus(),
      'changes' => array(),
      'properties' => array(),
    );

    foreach ($this->getChangesets() as $changeset) {
      $hunks = array();
      foreach ($changeset->getHunks() as $hunk) {
        $hunks[] = array(
          'oldOffset' => $hunk->getOldOffset(),
          'newOffset' => $hunk->getNewOffset(),
          'oldLength' => $hunk->getOldLen(),
          'newLength' => $hunk->getNewLen(),
          'addLines'  => null,
          'delLines'  => null,
          'isMissingOldNewline' => null,
          'isMissingNewNewline' => null,
          'corpus'    => $hunk->getChanges(),
        );
      }
      $change = array(
        'metadata'      => $changeset->getMetadata(),
        'oldPath'       => $changeset->getOldFile(),
        'currentPath'   => $changeset->getFilename(),
        'awayPaths'     => $changeset->getAwayPaths(),
        'oldProperties' => $changeset->getOldProperties(),
        'newProperties' => $changeset->getNewProperties(),
        'type'          => $changeset->getChangeType(),
        'fileType'      => $changeset->getFileType(),
        'commitHash'    => null,
        'addLines'      => $changeset->getAddLines(),
        'delLines'      => $changeset->getDelLines(),
        'hunks'         => $hunks,
      );
      $dict['changes'][] = $change;
    }

    $properties = id(new DifferentialDiffProperty())->loadAllWhere(
      'diffID = %d',
      $this->getID());
    foreach ($properties as $property) {
      $dict['properties'][$property->getName()] = $property->getData();
    }

    return $dict;
  }
}
