<?php

/**
 * Daemon which spawns nonterminating, death-resistant children.
 *
 * @group testcase
 */
final class PhutilProcessGroupDaemon extends PhutilTortureTestDaemon {

  public function run() {
    $root = phutil_get_library_root('phutil');
    $root = dirname($root);

    execx('%s', $root.'/scripts/daemon/torture/resist-death.php');
  }

}
