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
 * Collects old logs and caches to reduce the amount of data stored in the
 * database.
 *
 * @group daemon
 */
final class PhabricatorGarbageCollectorDaemon extends PhabricatorDaemon {

  public function run() {

    // Keep track of when we start and stop the GC so we can emit useful log
    // messages.
    $just_ran = false;

    do {
      $run_at   = PhabricatorEnv::getEnvConfig('gcdaemon.run-at');
      $run_for  = PhabricatorEnv::getEnvConfig('gcdaemon.run-for');

      // Just use the default timezone, we don't need to get fancy and try
      // to localize this.
      $start = strtotime($run_at);
      if ($start === false) {
        throw new Exception(
          "Configuration 'gcdaemon.run-at' could not be parsed: '{$run_at}'.");
      }

      $now = time();

      if ($now < $start || $now > ($start + $run_for)) {
        if ($just_ran) {
          echo "Stopped garbage collector.\n";
          $just_ran = false;
        }
        // The configuration says we can't collect garbage right now, so
        // just sleep until we can.
        $this->sleep(300);
        continue;
      }

      if (!$just_ran) {
        echo "Started garbage collector.\n";
        $just_ran = true;
      }

      $n_herald = $this->collectHeraldTranscripts();
      $n_daemon = $this->collectDaemonLogs();
      $n_parse  = $this->collectParseCaches();

      $collected = array(
        'Herald Transcript'           => $n_herald,
        'Daemon Log'                  => $n_daemon,
        'Differential Parse Cache'    => $n_parse,
      );
      $collected = array_filter($collected);

      foreach ($collected as $thing => $count) {
        if ($thing == 'Daemon Log' && !$this->getTraceMode()) {
          continue;
        }
        $count = number_format($count);
        echo "Garbage collected {$count} '{$thing}' objects.\n";
      }

      $total = array_sum($collected);
      if ($total < 100) {
        // We didn't max out any of the GCs so we're basically caught up. Ease
        // off the GC loop so we don't keep doing table scans just to delete
        // a handful of rows.
        $this->sleep(300);
      } else {
        $this->stillWorking();
      }
    } while (true);

  }

  private function collectHeraldTranscripts() {
    $ttl = PhabricatorEnv::getEnvConfig('gcdaemon.ttl.herald-transcripts');
    if ($ttl <= 0) {
      return 0;
    }

    $table = new HeraldTranscript();
    $conn_w = $table->establishConnection('w');

    queryfx(
      $conn_w,
      'UPDATE %T SET
          objectTranscript     = "",
          ruleTranscripts      = "",
          conditionTranscripts = "",
          applyTranscripts     = "",
          garbageCollected     = 1
        WHERE garbageCollected = 0 AND `time` < %d
        LIMIT 100',
      $table->getTableName(),
      time() - $ttl);

    return $conn_w->getAffectedRows();
  }

  private function collectDaemonLogs() {
    $ttl = PhabricatorEnv::getEnvConfig('gcdaemon.ttl.daemon-logs');
    if ($ttl <= 0) {
      return 0;
    }

    $table = new PhabricatorDaemonLogEvent();
    $conn_w = $table->establishConnection('w');

    queryfx(
      $conn_w,
      'DELETE FROM %T WHERE epoch < %d LIMIT 100',
      $table->getTableName(),
      time() - $ttl);

    return $conn_w->getAffectedRows();
  }

  private function collectParseCaches() {
    $key = 'gcdaemon.ttl.differential-parse-cache';
    $ttl = PhabricatorEnv::getEnvConfig($key);
    if ($ttl <= 0) {
      return 0;
    }

    $table = new DifferentialChangeset();
    $conn_w = $table->establishConnection('w');

    queryfx(
      $conn_w,
      'DELETE FROM %T WHERE dateCreated < %d LIMIT 100',
      DifferentialChangeset::TABLE_CACHE,
      time() - $ttl);

    return $conn_w->getAffectedRows();
  }

}
