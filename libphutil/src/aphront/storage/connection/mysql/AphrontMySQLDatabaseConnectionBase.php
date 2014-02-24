<?php

/**
 * @group storage
 */
abstract class AphrontMySQLDatabaseConnectionBase
  extends AphrontDatabaseConnection {

  private $configuration;
  private $connection;
  private $connectionPool = array();
  private $lastResult;

  private $nextError;

  abstract protected function connect();
  abstract protected function rawQuery($raw_query);
  abstract protected function rawQueries(array $raw_queries);
  abstract protected function fetchAssoc($result);
  abstract protected function getErrorCode($connection);
  abstract protected function getErrorDescription($connection);
  abstract protected function closeConnection();
  abstract protected function freeResult($result);

  public function __construct(array $configuration) {
    $this->configuration  = $configuration;
  }

  public function __clone() {
    $this->establishConnection();
  }

  public function close() {
    if ($this->lastResult) {
      $this->lastResult = null;
    }
    if ($this->connection) {
      $this->closeConnection();
      $this->connection = null;
    }
  }

  public function escapeColumnName($name) {
    return '`'.str_replace('`', '``', $name).'`';
  }


  public function escapeMultilineComment($comment) {
    // These can either terminate a comment, confuse the hell out of the parser,
    // make MySQL execute the comment as a query, or, in the case of semicolon,
    // are quasi-dangerous because the semicolon could turn a broken query into
    // a working query plus an ignored query.

    static $map = array(
      '--'  => '(DOUBLEDASH)',
      '*/'  => '(STARSLASH)',
      '//'  => '(SLASHSLASH)',
      '#'   => '(HASH)',
      '!'   => '(BANG)',
      ';'   => '(SEMICOLON)',
    );

    $comment = str_replace(
      array_keys($map),
      array_values($map),
      $comment);

    // For good measure, kill anything else that isn't a nice printable
    // character.
    $comment = preg_replace('/[^\x20-\x7F]+/', ' ', $comment);

    return '/* '.$comment.' */';
  }

  public function escapeStringForLikeClause($value) {
    $value = addcslashes($value, '\%_');
    $value = $this->escapeUTF8String($value);
    return $value;
  }

  protected function getConfiguration($key, $default = null) {
    return idx($this->configuration, $key, $default);
  }

  private function establishConnection() {
    $host = $this->getConfiguration('host');
    $database = $this->getConfiguration('database');

    $profiler = PhutilServiceProfiler::getInstance();
    $call_id = $profiler->beginServiceCall(
      array(
        'type'      => 'connect',
        'host'      => $host,
        'database'  => $database,
      ));

    $retries = max(1, $this->getConfiguration('retries', 3));
    while ($retries--) {
      try {
        $conn = $this->connect();
        $profiler->endServiceCall($call_id, array());
        break;
      } catch (AphrontQueryException $ex) {
        if ($retries && $ex->getCode() == 2003) {
          $class = get_class($ex);
          $message = $ex->getMessage();
          phlog("Retrying ({$retries}) after {$class}: {$message}");
        } else {
          $profiler->endServiceCall($call_id, array());
          throw $ex;
        }
      }
    }

    $this->connection = $conn;
  }

  protected function requireConnection() {
    if (!$this->connection) {
      if ($this->connectionPool) {
        $this->connection = array_pop($this->connectionPool);
      } else {
        $this->establishConnection();
      }
    }
    return $this->connection;
  }

  protected function beginAsyncConnection() {
    $connection = $this->requireConnection();
    $this->connection = null;
    return $connection;
  }

  protected function endAsyncConnection($connection) {
    if ($this->connection) {
      $this->connectionPool[] = $this->connection;
    }
    $this->connection = $connection;
  }

  public function selectAllResults() {
    $result = array();
    $res = $this->lastResult;
    if ($res == null) {
      throw new Exception('No query result to fetch from!');
    }
    while (($row = $this->fetchAssoc($res))) {
      $result[] = $row;
    }
    return $result;
  }

  public function executeRawQuery($raw_query) {
    $this->lastResult = null;
    $retries = max(1, $this->getConfiguration('retries', 3));
    while ($retries--) {
      try {
        $this->requireConnection();
        $is_write = $this->checkWrite($raw_query);

        $profiler = PhutilServiceProfiler::getInstance();
        $call_id = $profiler->beginServiceCall(
          array(
            'type'    => 'query',
            'config'  => $this->configuration,
            'query'   => $raw_query,
            'write'   => $is_write,
          ));

        $result = $this->rawQuery($raw_query);

        $profiler->endServiceCall($call_id, array());

        if ($this->nextError) {
          $result = null;
        }

        if ($result) {
          $this->lastResult = $result;
          break;
        }

        $this->throwQueryException($this->connection);
      } catch (AphrontQueryConnectionLostException $ex) {
        if ($this->isInsideTransaction()) {
          // Zero out the transaction state to prevent a second exception
          // ("program exited with open transaction") from being thrown, since
          // we're about to throw a more relevant/useful one instead.
          $state = $this->getTransactionState();
          while ($state->getDepth()) {
            $state->decreaseDepth();
          }

          // We can't close the connection before this because
          // isInsideTransaction() and getTransactionState() depend on the
          // connection.
          $this->close();

          throw $ex;
        }

        $this->close();

        if (!$retries) {
          throw $ex;
        }
      }
    }
  }

  public function executeRawQueries(array $raw_queries) {
    if (!$raw_queries) {
      return array();
    }

    $is_write = false;
    foreach ($raw_queries as $key => $raw_query) {
      $is_write = $is_write || $this->checkWrite($raw_query);
      $raw_queries[$key] = rtrim($raw_query, "\r\n\t ;");
    }

    $profiler = PhutilServiceProfiler::getInstance();
    $call_id = $profiler->beginServiceCall(
      array(
        'type'    => 'multi-query',
        'config'  => $this->configuration,
        'queries' => $raw_queries,
        'write'   => $is_write,
      ));

    $results = $this->rawQueries($raw_queries);

    $profiler->endServiceCall($call_id, array());

    return $results;
  }

  protected function processResult($result) {
    if (!$result) {
      try {
        $this->throwQueryException($this->requireConnection());
      } catch (Exception $ex) {
        return $ex;
      }
    } else if (is_bool($result)) {
      return $this->getAffectedRows();
    }
    $rows = array();
    while (($row = $this->fetchAssoc($result))) {
      $rows[] = $row;
    }
    $this->freeResult($result);
    return $rows;
  }

  protected function checkWrite($raw_query) {
    // NOTE: The opening "(" allows queries in the form of:
    //
    //   (SELECT ...) UNION (SELECT ...)
    $is_write = !preg_match('/^[(]*(SELECT|SHOW|EXPLAIN)\s/', $raw_query);
    if ($is_write) {
      AphrontWriteGuard::willWrite();
      return true;
    }
    return false;
  }

  protected function throwQueryException($connection) {
    if ($this->nextError) {
      $errno = $this->nextError;
      $error = 'Simulated error.';
      $this->nextError = null;
    } else {
      $errno = $this->getErrorCode($connection);
      $error = $this->getErrorDescription($connection);
    }
    $this->throwQueryCodeException($errno, $error);
  }

  protected function throwQueryCodeException($errno, $error) {
    $exmsg = "#{$errno}: {$error}";

    switch ($errno) {
      case 2013: // Connection Dropped
        throw new AphrontQueryConnectionLostException($exmsg);
      case 2006: // Gone Away
        $more = "This error may occur if your MySQL 'wait_timeout' ".
                "or 'max_allowed_packet' configuration values are set too low.";
        throw new AphrontQueryConnectionLostException("{$exmsg}\n\n{$more}");
      case 1213: // Deadlock
      case 1205: // Lock wait timeout exceeded
        throw new AphrontQueryDeadlockException($exmsg);
      case 1062: // Duplicate Key
        // NOTE: In some versions of MySQL we get a key name back here, but
        // older versions just give us a key index ("key 2") so it's not
        // portable to parse the key out of the error and attach it to the
        // exception.
        throw new AphrontQueryDuplicateKeyException($exmsg);
      case 1044: // Access denied to database
      case 1045: // Access denied (auth)
      case 1142: // Access denied to table
      case 1143: // Access denied to column
        throw new AphrontQueryAccessDeniedException($exmsg);
      case 1146: // No such table
      case 1049: // No such database
      case 1054: // Unknown column "..." in field list
        throw new AphrontQuerySchemaException($exmsg);
      default:
        // TODO: 1064 is syntax error, and quite terrible in production.
        throw new AphrontQueryException($exmsg);
    }
  }

  /**
   * Force the next query to fail with a simulated error. This should be used
   * ONLY for unit tests.
   */
  public function simulateErrorOnNextQuery($error) {
    $this->nextError = $error;
    return $this;
  }

  /**
   * Check inserts for characters outside of the BMP. Even with the strictest
   * settings, MySQL will silently truncate data when it encounters these, which
   * can lead to data loss and security problems.
   */
  protected function validateUTF8String($string) {
    if (phutil_is_utf8_with_only_bmp_characters($string)) {
      return;
    }

    throw new AphrontQueryCharacterSetException(
      pht(
        'Attempting to construct a query containing characters outside of '.
        'the Unicode Basic Multilingual Plane. MySQL will silently truncate '.
        'this data if it is inserted into a `utf8` column. Use the `%%B` '.
        'conversion to escape binary strings data.'));
  }

}
