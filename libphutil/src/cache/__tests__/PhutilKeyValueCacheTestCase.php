<?php

final class PhutilKeyValueCacheTestCase extends ArcanistPhutilTestCase {

  public function testInRequestCache() {
    $cache = new PhutilKeyValueCacheInRequest();
    $this->doCacheTest($cache);
    $cache->destroyCache();
  }

  public function testOnDiskCache() {
    $cache = new PhutilKeyValueCacheOnDisk();
    $cache->setCacheFile(new TempFile());
    $this->doCacheTest($cache);
    $cache->destroyCache();
  }

  public function testAPCCache() {
    $cache = new PhutilKeyValueCacheAPC();
    if (!$cache->isAvailable()) {
      $this->assertSkipped("Cache not available.");
    }
    $this->doCacheTest($cache);
  }

  public function testDirectoryCache() {
    $cache = new PhutilKeyValueCacheDirectory();

    $dir = Filesystem::createTemporaryDirectory();
    $cache->setCacheDirectory($dir);
    $this->doCacheTest($cache);
    $cache->destroyCache();
  }

  public function testDirectoryCacheSpecialDirectoryRules() {
    $cache = new PhutilKeyValueCacheDirectory();

    $dir = Filesystem::createTemporaryDirectory();
    $dir = $dir.'/dircache/';
    $cache->setCacheDirectory($dir);

    $cache->setKey('a', 1);
    $this->assertEqual(true, Filesystem::pathExists($dir.'/a.cache'));

    $cache->setKey('a/b', 1);
    $this->assertEqual(true, Filesystem::pathExists($dir.'/a/'));
    $this->assertEqual(true, Filesystem::pathExists($dir.'/a/b.cache'));

    $cache->deleteKey('a/b');
    $this->assertEqual(false, Filesystem::pathExists($dir.'/a/'));
    $this->assertEqual(false, Filesystem::pathExists($dir.'/a/b.cache'));

    $cache->destroyCache();
    $this->assertEqual(false, Filesystem::pathExists($dir));
  }

  public function testCacheStack() {
    $req_cache = new PhutilKeyValueCacheInRequest();
    $disk_cache = new PhutilKeyValueCacheOnDisk();
    $disk_cache->setCacheFile(new TempFile());
    $apc_cache = new PhutilKeyValueCacheAPC();

    $stack = array(
      $req_cache,
      $disk_cache,
    );

    if ($apc_cache->isAvailable()) {
      $stack[] = $apc_cache;
    }

    $cache = new PhutilKeyValueCacheStack();
    $cache->setCaches($stack);

    $this->doCacheTest($cache);

    $disk_cache->destroyCache();
    $req_cache->destroyCache();
  }

  private function doCacheTest(PhutilKeyValueCache $cache) {
    $key1 = 'test:'.mt_rand();
    $key2 = 'test:'.mt_rand();

    $default = 'cache-miss';
    $value1  = 'cache-hit1';
    $value2  = 'cache-hit2';

    $test_info = get_class($cache);

    // Test that we miss correctly on missing values.

    $this->assertEqual(
      $default,
      $cache->getKey($key1, $default),
      $test_info);
    $this->assertEqual(
      array(
      ),
      $cache->getKeys(array($key1, $key2)),
      $test_info);


    // Test that we can set individual keys.

    $cache->setKey($key1, $value1);
    $this->assertEqual(
      $value1,
      $cache->getKey($key1, $default),
      $test_info);
    $this->assertEqual(
      array(
        $key1 => $value1,
      ),
      $cache->getKeys(array($key1, $key2)),
      $test_info);


    // Test that we can delete individual keys.

    $cache->deleteKey($key1);

    $this->assertEqual(
      $default,
      $cache->getKey($key1, $default),
      $test_info);
    $this->assertEqual(
      array(
      ),
      $cache->getKeys(array($key1, $key2)),
      $test_info);



    // Test that we can set multiple keys.

    $cache->setKeys(
      array(
        $key1 => $value1,
        $key2 => $value2,
      ));

    $this->assertEqual(
      $value1,
      $cache->getKey($key1, $default),
      $test_info);
    $this->assertEqual(
      array(
        $key1 => $value1,
        $key2 => $value2,
      ),
      $cache->getKeys(array($key1, $key2)),
      $test_info);


    // Test that we can delete multiple keys.

    $cache->deleteKeys(array($key1, $key2));

    $this->assertEqual(
      $default,
      $cache->getKey($key1, $default),
      $test_info);
    $this->assertEqual(
      array(
      ),
      $cache->getKeys(array($key1, $key2)),
      $test_info);


    // NOTE: The TTL tests are necessarily slow (we must sleep() through the
    // TTLs) and do not work with APC (it does not TTL until the next request)
    // so they're disabled by default. If you're developing the cache stack,
    // it may be useful to run them.

    return;

    // Test that keys expire when they TTL.

    $cache->setKey($key1, $value1, 1);
    $cache->setKey($key2, $value2, 5);

    $this->assertEqual($value1, $cache->getKey($key1, $default));
    $this->assertEqual($value2, $cache->getKey($key2, $default));

    sleep(2);

    $this->assertEqual($default, $cache->getKey($key1, $default));
    $this->assertEqual($value2, $cache->getKey($key2, $default));


    // Test that setting a 0 TTL overwrites a nonzero TTL.

    $cache->setKey($key1, $value1, 1);
    $this->assertEqual($value1, $cache->getKey($key1, $default));
    $cache->setKey($key1, $value1, 0);
    $this->assertEqual($value1, $cache->getKey($key1, $default));
    sleep(2);
    $this->assertEqual($value1, $cache->getKey($key1, $default));
  }

}
