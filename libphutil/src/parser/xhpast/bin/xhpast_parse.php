<?php

/**
 * @group xhpast
 */
function xhpast_is_available() {
  static $available;
  if ($available === null) {
    $available = false;
    $bin = xhpast_get_binary_path();
    if (Filesystem::pathExists($bin)) {
      list($err, $stdout) = exec_manual('%s --version', $bin);
      if (!$err) {
        $version = trim($stdout);
        if ($version === "xhpast version 0.62") {
          $available = true;
        }
      }
    }
  }
  return $available;
}


/**
 * @group xhpast
 */
function xhpast_get_binary_path() {
  if (phutil_is_windows()) {
    return dirname(__FILE__).'\\xhpast.exe';
  }
  return dirname(__FILE__).'/xhpast';
}


/**
 * @group xhpast
 */
function xhpast_get_build_instructions() {
  $root = phutil_get_library_root('phutil');
  $make = $root.'/../scripts/build_xhpast.sh';
  $make = Filesystem::resolvePath($make);
  return <<<EOHELP
Your version of 'xhpast' is unbuilt or out of date. Run this script to build it:

  \$ {$make}

EOHELP;
}


/**
 * @group xhpast
 */
function xhpast_get_parser_future($data) {
  if (!xhpast_is_available()) {
    throw new Exception(xhpast_get_build_instructions());
  }
  $future = new ExecFuture('%s', xhpast_get_binary_path());
  $future->write($data);

  return $future;
}
