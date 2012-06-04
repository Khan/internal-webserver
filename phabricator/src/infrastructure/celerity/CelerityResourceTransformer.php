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
 * @group celerity
 */
final class CelerityResourceTransformer {

  private $minify;
  private $rawResourceMap;
  private $celerityMap;

  public function setMinify($minify) {
    $this->minify = $minify;
    return $this;
  }

  public function setRawResourceMap(array $raw_resource_map) {
    $this->rawResourceMap = $raw_resource_map;
    return $this;
  }

  public function setCelerityMap(CelerityResourceMap $celerity_map) {
    $this->celerityMap = $celerity_map;
    return $this;
  }

  public function transformResource($path, $data) {
    $type = self::getResourceType($path);

    switch ($type) {
      case 'css':
        $data = preg_replace_callback(
          '@url\s*\((\s*[\'"]?/rsrc/.*?)\)@s',
          array($this, 'translateResourceURI'),
          $data);
        break;
    }

    if (!$this->minify) {
      return $data;
    }

    // Some resources won't survive minification (like Raphael.js), and are
    // marked so as not to be minified.
    if (strpos($data, '@'.'do-not-minify') !== false) {
      return $data;
    }

    switch ($type) {
      case 'css':
        // Remove comments.
        $data = preg_replace('@/\*.*?\*/@s', '', $data);
        // Remove whitespace around symbols.
        $data = preg_replace('@\s*([{}:;,])\s*@', '\1', $data);
        // Remove unnecessary semicolons.
        $data = preg_replace('@;}@', '}', $data);
        // Replace #rrggbb with #rgb when possible.
        $data = preg_replace(
          '@#([a-f0-9])\1([a-f0-9])\2([a-f0-9])\3@i',
          '#\1\2\3',
          $data);
        $data = trim($data);
        break;
      case 'js':
        $root = dirname(phutil_get_library_root('phabricator'));
        $bin = $root.'/externals/javelin/support/jsxmin/jsxmin';

        if (@file_exists($bin)) {
          $future = new ExecFuture("{$bin} __DEV__:0");
          $future->write($data);
          list($err, $result) = $future->resolve();
          if (!$err) {
            $data = $result;
          }
        }
        break;
    }

    return $data;
  }

  public static function getResourceType($path) {
    return last(explode('.', $path));
  }

  public function translateResourceURI(array $matches) {
    $uri = trim($matches[1], "'\" \r\t\n");

    if ($this->rawResourceMap) {
      if (isset($this->rawResourceMap[$uri]['uri'])) {
        $uri = $this->rawResourceMap[$uri]['uri'];
      }
    } else if ($this->celerityMap) {
      $info = $this->celerityMap->lookupFileInformation($uri);
      if ($info) {
        $uri = $info['uri'];
      }
    }

    return 'url('.$uri.')';
  }

}
