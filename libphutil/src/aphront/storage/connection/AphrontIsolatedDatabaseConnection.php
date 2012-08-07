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
 * @group storage
 */
final class AphrontIsolatedDatabaseConnection
  extends AphrontDatabaseConnection {

  private $configuration;
  private static $nextInsertID;
  private $insertID;

  private $transcript = array();

  public function __construct(array $configuration) {
    $this->configuration = $configuration;

    if (self::$nextInsertID === null) {
      // Generate test IDs into a distant ID space to reduce the risk of
      // collisions and make them distinctive.
      self::$nextInsertID = 55555000000 + mt_rand(0, 1000);
    }
  }

  public function close() {
    return;
  }

  public function escapeString($string) {
    return '<S>';
  }

  public function escapeColumnName($name) {
    return '<C>';
  }

  public function escapeMultilineComment($comment) {
    return '<K>';
  }

  public function escapeStringForLikeClause($value) {
    return '<L>';
  }

  private function getConfiguration($key, $default = null) {
    return idx($this->configuration, $key, $default);
  }

  public function getInsertID() {
    return $this->insertID;
  }

  public function getAffectedRows() {
    return $this->affectedRows;
  }

  public function selectAllResults() {
    return $this->allResults;
  }

  public function executeRawQuery($raw_query) {

    // NOTE: "[\s<>K]*" allows any number of (properly escaped) comments to
    // appear prior to the allowed keyword, since this connection escapes
    // them as "<K>" (above).

    $keywords = array(
      'INSERT',
      'UPDATE',
      'DELETE',
      'START',
      'SAVEPOINT',
      'COMMIT',
      'ROLLBACK',
    );
    $preg_keywords = array();
    foreach ($keywords as $key => $word) {
      $preg_keywords[] = preg_quote($word, '/');
    }
    $preg_keywords = implode('|', $preg_keywords);

    if (!preg_match('/^[\s<>K]*('.$preg_keywords.')\s*/i', $raw_query)) {
      throw new AphrontQueryNotSupportedException(
        "Database isolation currently only supports some queries. You are ".
        "trying to issue a query which does not begin with an allowed ".
        "keyword (".implode(', ', $keywords)."): '".$raw_query."'");
    }

    $this->transcript[] = $raw_query;

    // NOTE: This method is intentionally simplified for now, since we're only
    // using it to stub out inserts/updates. In the future it will probably need
    // to grow more powerful.

    $this->allResults = array();

    // NOTE: We jitter the insert IDs to keep tests honest; a test should cover
    // the relationship between objects, not their exact insertion order. This
    // guarantees that IDs are unique but makes it impossible to hard-code tests
    // against this specific implementation detail.
    self::$nextInsertID += mt_rand(1, 10);
    $this->insertID = self::$nextInsertID;
    $this->affectedRows = 1;
  }

  public function getQueryTranscript() {
    return $this->transcript;
  }

}
