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

abstract class PhabricatorRepositoryCommitChangeParserWorker
  extends PhabricatorRepositoryCommitParserWorker {

  public function getRequiredLeaseTime() {
    // It can take a very long time to parse commits; some commits in the
    // Facebook repository affect many millions of paths. Acquire 24h leases.
    return 60 * 60 * 24;
  }

  public static function lookupOrCreatePaths(array $paths) {
    $repository = new PhabricatorRepository();
    $conn_w = $repository->establishConnection('w');

    $result_map = self::lookupPaths($paths);

    $missing_paths = array_fill_keys($paths, true);
    $missing_paths = array_diff_key($missing_paths, $result_map);
    $missing_paths = array_keys($missing_paths);

    if ($missing_paths) {
      foreach (array_chunk($missing_paths, 128) as $path_chunk) {
        $sql = array();
        foreach ($path_chunk as $path) {
          $sql[] = qsprintf($conn_w, '(%s, %s)', $path, md5($path));
        }
        queryfx(
          $conn_w,
          'INSERT IGNORE INTO %T (path, pathHash) VALUES %Q',
          PhabricatorRepository::TABLE_PATH,
          implode(', ', $sql));
      }
      $result_map += self::lookupPaths($missing_paths);
    }

    return $result_map;
  }

  private static function lookupPaths(array $paths) {
    $repository = new PhabricatorRepository();
    $conn_w = $repository->establishConnection('w');

    $result_map = array();
    foreach (array_chunk($paths, 128) as $path_chunk) {
      $chunk_map = queryfx_all(
        $conn_w,
        'SELECT path, id FROM %T WHERE pathHash IN (%Ls)',
        PhabricatorRepository::TABLE_PATH,
        array_map('md5', $path_chunk));
      foreach ($chunk_map as $row) {
        $result_map[$row['path']] = $row['id'];
      }
    }
    return $result_map;
  }

  protected function finishParse() {
    $commit = $this->commit;
    PhabricatorSearchCommitIndexer::indexCommit($commit);

    if ($this->shouldQueueFollowupTasks()) {
      $owner_task = new PhabricatorWorkerTask();
      $owner_task->setTaskClass('PhabricatorRepositoryCommitOwnersWorker');
      $owner_task->setData(
        array(
          'commitID' => $commit->getID(),
        ));
      $owner_task->save();
    }
  }

}
