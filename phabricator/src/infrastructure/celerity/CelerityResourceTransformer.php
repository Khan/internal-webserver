<?php

/**
 * @group celerity
 */
final class CelerityResourceTransformer {

  private $minify;
  private $rawResourceMap;
  private $celerityMap;
  private $translateURICallback;
  private $currentPath;

  public function setTranslateURICallback($translate_uricallback) {
    $this->translateURICallback = $translate_uricallback;
    return $this;
  }

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

  /**
   * @phutil-external-symbol function jsShrink
   */
  public function transformResource($path, $data) {
    $type = self::getResourceType($path);

    switch ($type) {
      case 'css':
        $data = $this->replaceCSSVariables($path, $data);
        $data = preg_replace_callback(
          '@url\s*\((\s*[\'"]?.*?)\)@s',
          nonempty(
            $this->translateURICallback,
            array($this, 'translateResourceURI')),
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

        // If `jsxmin` is available, use it. jsxmin is the Javelin minifier and
        // produces the smallest output, but is complicated to build.
        if (Filesystem::binaryExists('jsxmin')) {
          $future = new ExecFuture('jsxmin __DEV__:0');
          $future->write($data);
          list($err, $result) = $future->resolve();
          if (!$err) {
            $data = $result;
            break;
          }
        }

        // If `jsxmin` is not available, use `JsShrink`, which doesn't compress
        // quite as well but is always available.
        $root = dirname(phutil_get_library_root('phabricator'));
        require_once $root.'/externals/JsShrink/jsShrink.php';
        $data = jsShrink($data);

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

  private function replaceCSSVariables($path, $data) {
    $this->currentPath = $path;
    return preg_replace_callback(
      '/{\$([^}]+)}/',
      array($this, 'replaceCSSVariable'),
      $data);
  }

  public function replaceCSSVariable($matches) {
    static $map = array(
      'red'           => '#c0392b',
      'lightred'      => '#f4dddb',
      'orange'        => '#e67e22',
      'lightorange'   => '#f7e2d4',
      'yellow'        => '#f1c40f',
      'lightyellow'   => '#fdf5d4',
      'green'         => '#139543',
      'lightgreen'    => '#d7eddf',
      'blue'          => '#2980b9',
      'lightblue'     => '#daeaf3',
      'sky'           => '#3498db',
      'lightsky'      => '#ddeef9',
      'indigo'        => '#c6539d',
      'lightindigo'   => '#f5e2ef',
      'violet'        => '#8e44ad',
      'lightviolet'   => '#ecdff1',
    );

    $var_name = $matches[1];
    if (empty($map[$var_name])) {
      $path = $this->currentPath;
      throw new Exception(
        "CSS file '{$path}' has unknown variable '{$var_name}'.");
    }

    return $map[$var_name];
  }

}
