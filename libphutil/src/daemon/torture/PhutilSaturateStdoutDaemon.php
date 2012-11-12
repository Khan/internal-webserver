<?php

/**
 * Daemon which dumps huge amounts of data to stdout.
 *
 * @group testcase
 */
final class PhutilSaturateStdoutDaemon extends PhutilTortureTestDaemon {

  public function run() {
    for ($ii = 0; $ii < 512; $ii++) {
      echo str_repeat('~', 1024 * 1024);
    }
  }

}
