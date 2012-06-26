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
 * Displays user's git branches
 *
 * @group workflow
 */
final class ArcanistBranchWorkflow extends ArcanistBaseWorkflow {

  private $branches;

  public function getCommandSynopses() {
    return phutil_console_format(<<<EOTEXT
      **branch** [__options__]
EOTEXT
      );
  }

  public function getCommandHelp() {
    return phutil_console_format(<<<EOTEXT
          Supports: git
          A wrapper on 'git branch'. It pulls data from Differential and
          displays the revision status next to the branch name.

          By default, branches are sorted chronologically. You can sort them
          by status instead with __--by-status__.

          By default, branches that are "Closed" or "Abandoned" are not
          displayed. You can show them with __--view-all__.
EOTEXT
      );
  }

  public function requiresConduit() {
    return true;
  }

  public function requiresRepositoryAPI() {
    return true;
  }

  public function requiresAuthentication() {
    return true;
  }


  public function getArguments() {
    return array(
      'view-all' => array(
        'help' => 'Include closed and abandoned revisions',
      ),
      'by-status' => array(
        'help' => 'Sort branches by status instead of time.',
      ),
    );
  }

  public function run() {
    $repository_api = $this->getRepositoryAPI();
    if (!($repository_api instanceof ArcanistGitAPI)) {
      throw new ArcanistUsageException(
        'arc branch is only supported under git.');
    }

    $branches = $repository_api->getAllBranches();
    if (!$branches) {
      throw new ArcanistUsageException('No branches in this working copy.');
    }

    $commit_map = $this->loadCommitInfo($branches, $repository_api);
    foreach ($branches as $key => $branch) {
      $branches[$key] += $commit_map[$branch['hash']];
    }

    $revisions = $this->loadRevisions($branches);

    $this->printBranches($branches, $revisions);

    return 0;
  }

  private function loadCommitInfo(
    array $branches,
    ArcanistRepositoryAPI $repository_api) {

    $commits = ipull($branches, 'hash');
    list($info) = $repository_api->execxLocal(
      'log --format=%C %Ls --',
      '%H%x01%ct%x01%T%x01%s%n%b%x02',
      $commits);

    $commit_map = array();

    $info = array_filter(explode("\2", trim($info)));
    foreach ($info as $line) {
      list($hash, $epoch, $tree, $text) = explode("\1", trim($line), 4);
      $message = ArcanistDifferentialCommitMessage::newFromRawCorpus($text);
      $id = $message->getRevisionID();

      $commit_map[$hash] = array(
        'epoch'       => (int)$epoch,
        'tree'        => $tree,
        'revisionID'  => $id,
      );
    }

    return $commit_map;
  }

  private function loadRevisions(array $branches) {
    $ids = array();
    $hashes = array();

    foreach ($branches as $branch) {
      if ($branch['revisionID']) {
        $ids[] = $branch['revisionID'];
      }
      $hashes[] = array('gtcm', $branch['hash']);
      $hashes[] = array('gttr', $branch['tree']);
    }

    $calls = array();

    if ($ids) {
      $calls[] = $this->getConduit()->callMethod(
        'differential.query',
        array(
          'ids' => $ids,
        ));
    }

    if ($hashes) {
      $calls[] = $this->getConduit()->callMethod(
        'differential.query',
        array(
          'commitHashes' => $hashes,
        ));
    }

    $results = array();
    foreach (Futures($calls) as $call) {
      $results[] = $call->resolve();
    }

    return array_mergev($results);
  }

  private function printBranches(array $branches, array $revisions) {
    $revisions = ipull($revisions, null, 'id');

    static $color_map = array(
      'Closed'          => 'cyan',
      'Needs Review'    => 'magenta',
      'Needs Revision'  => 'red',
      'Accepted'        => 'green',
      'No Revision'     => 'blue',
      'Abandoned'       => 'default',
    );

    static $ssort_map = array(
      'Closed'          => 1,
      'No Revision'     => 2,
      'Needs Review'    => 3,
      'Needs Revision'  => 4,
      'Accepted'        => 5,
    );

    $out = array();
    foreach ($branches as $branch) {
      $revision = idx($revisions, idx($branch, 'revisionID'));

      // If we haven't identified a revision by ID, try to identify it by hash.
      if (!$revision) {
        foreach ($revisions as $rev) {
          $hashes = idx($rev, 'hashes', array());
          foreach ($hashes as $hash) {
            if (($hash[0] == 'gtcm' && $hash[1] == $branch['hash']) ||
                ($hash[0] == 'gttr' && $hash[1] == $branch['tree'])) {
              $revision = $rev;
              break;
            }
          }
        }
      }

      if ($revision) {
        $desc = 'D'.$revision['id'].': '.$revision['title'];
        $status = $revision['statusName'];
      } else {
        $desc = $branch['desc'];
        $status = 'No Revision';
      }

      if (!$this->getArgument('view-all')) {
        if ($status == 'Closed' || $status == 'Abandoned') {
          continue;
        }
      }

      $epoch = $branch['epoch'];

      $color = idx($color_map, $status, 'default');
      $ssort = sprintf('%d%012d', idx($ssort_map, $status, 0), $epoch);

      $out[] = array(
        'name'      => $branch['name'],
        'current'   => $branch['current'],
        'status'    => $status,
        'desc'      => $desc,
        'color'     => $color,
        'esort'     => $epoch,
        'ssort'     => $ssort,
      );
    }

    $len_name = max(array_map('strlen', ipull($out, 'name'))) + 2;
    $len_status = max(array_map('strlen', ipull($out, 'status'))) + 2;

    if ($this->getArgument('by-status')) {
      $out = isort($out, 'ssort');
    } else {
      $out = isort($out, 'esort');
    }

    $console = PhutilConsole::getConsole();
    foreach ($out as $line) {
      $color = $line['color'];
      $console->writeOut(
        "%s **%s** <fg:{$color}>%s</fg> %s\n",
        $line['current'] ? '* ' : '  ',
        str_pad($line['name'], $len_name),
        str_pad($line['status'], $len_status),
        $line['desc']);
    }
  }

}
