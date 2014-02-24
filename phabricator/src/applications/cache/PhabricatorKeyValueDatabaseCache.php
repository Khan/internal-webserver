<?php

final class PhabricatorKeyValueDatabaseCache
  extends PhutilKeyValueCache {

  const CACHE_FORMAT_RAW        = 'raw';
  const CACHE_FORMAT_DEFLATE    = 'deflate';

  public function setKeys(array $keys, $ttl = null) {
    if ($keys) {
      $map = $this->digestKeys(array_keys($keys));
      $conn_w = $this->establishConnection('w');

      $sql = array();
      foreach ($map as $key => $hash) {
        $value = $keys[$key];

        list($format, $storage_value) = $this->willWriteValue($key, $value);

        $sql[] = qsprintf(
          $conn_w,
          '(%s, %s, %s, %B, %d, %nd)',
          $hash,
          $key,
          $format,
          $storage_value,
          time(),
          $ttl ? (time() + $ttl) : null);
      }

      $guard = AphrontWriteGuard::beginScopedUnguardedWrites();
        foreach (PhabricatorLiskDAO::chunkSQL($sql) as $chunk) {
            queryfx(
              $conn_w,
              'INSERT INTO %T
                (cacheKeyHash, cacheKey, cacheFormat, cacheData,
                  cacheCreated, cacheExpires) VALUES %Q
                ON DUPLICATE KEY UPDATE
                  cacheKey = VALUES(cacheKey),
                  cacheFormat = VALUES(cacheFormat),
                  cacheData = VALUES(cacheData),
                  cacheCreated = VALUES(cacheCreated),
                  cacheExpires = VALUES(cacheExpires)',
              $this->getTableName(),
              $chunk);
        }
      unset($guard);
    }

    return $this;
  }

  public function getKeys(array $keys) {
    $results = array();
    if ($keys) {
      $map = $this->digestKeys($keys);

      $rows = queryfx_all(
        $this->establishConnection('r'),
        'SELECT * FROM %T WHERE cacheKeyHash IN (%Ls)',
        $this->getTableName(),
        $map);
      $rows = ipull($rows, null, 'cacheKey');

      foreach ($keys as $key) {
        if (empty($rows[$key])) {
          continue;
        }

        $row = $rows[$key];

        if ($row['cacheExpires'] && ($row['cacheExpires'] < time())) {
          continue;
        }

        try {
          $results[$key] = $this->didReadValue(
            $row['cacheFormat'],
            $row['cacheData']);
        } catch (Exception $ex) {
          // Treat this as a cache miss.
          phlog($ex);
        }
      }
    }

    return $results;
  }

  public function deleteKeys(array $keys) {
    if ($keys) {
      $map = $this->digestKeys($keys);
      queryfx(
        $this->establishConnection('w'),
        'DELETE FROM %T WHERE cacheKeyHash IN (%Ls)',
        $this->getTableName(),
        $keys);
    }

    return $this;
  }

  public function destroyCache() {
    queryfx(
      $this->establishConnection('w'),
      'DELETE FROM %T',
      $this->getTableName());
    return $this;
  }


/* -(  Raw Cache Access  )--------------------------------------------------- */


  public function establishConnection($mode) {
    // TODO: This is the only concrete table we have on the database right
    // now.
    return id(new PhabricatorMarkupCache())->establishConnection($mode);
  }

  public function getTableName() {
    return 'cache_general';
  }


/* -(  Implementation  )----------------------------------------------------- */


  private function digestKeys(array $keys) {
    $map = array();
    foreach ($keys as $key) {
      $map[$key] = PhabricatorHash::digestForIndex($key);
    }
    return $map;
  }

  private function willWriteValue($key, $value) {
    if (!is_string($value)) {
      throw new Exception("Only strings may be written to the DB cache!");
    }

    static $can_deflate;
    if ($can_deflate === null) {
      $can_deflate = function_exists('gzdeflate') &&
                     PhabricatorEnv::getEnvConfig('cache.enable-deflate');
    }

    // If the value is larger than 1KB, we have gzdeflate(), we successfully
    // can deflate it, and it benefits from deflation, store it deflated.
    if ($can_deflate) {
      $len = strlen($value);
      if ($len > 1024) {
        $deflated = gzdeflate($value);
        if ($deflated !== false) {
          $deflated_len = strlen($deflated);
          if ($deflated_len < ($len / 2)) {
            return array(self::CACHE_FORMAT_DEFLATE, $deflated);
          }
        }
      }
    }

    return array(self::CACHE_FORMAT_RAW, $value);
  }

  private function didReadValue($format, $value) {
    switch ($format) {
      case self::CACHE_FORMAT_RAW:
        return $value;
      case self::CACHE_FORMAT_DEFLATE:
        if (!function_exists('gzinflate')) {
          throw new Exception("No gzinflate() to read deflated cache.");
        }
        $value = gzinflate($value);
        if ($value === false) {
          throw new Exception("Failed to deflate cache.");
        }
        return $value;
      default:
        throw new Exception("Unknown cache format.");
    }
  }


}
