<?php

final class PhabricatorSetupCheckMySQL extends PhabricatorSetupCheck {

  protected function executeChecks() {
    $conn_raw = id(new PhabricatorUser())->establishConnection('w');

    $max_allowed_packet = queryfx_one(
      $conn_raw,
      'SHOW VARIABLES LIKE %s',
      'max_allowed_packet');
    $max_allowed_packet = idx($max_allowed_packet, 'Value', PHP_INT_MAX);

    $recommended_minimum = 1024 * 1024;
    if ($max_allowed_packet < $recommended_minimum) {
      $message = pht(
        "MySQL is configured with a very small 'max_allowed_packet' (%d), ".
        "which may cause some large writes to fail. Strongly consider raising ".
        "this to at least %d in your MySQL configuration.",
        $max_allowed_packet,
        $recommended_minimum);

      $this->newIssue('mysql.max_allowed_packet')
        ->setName(pht('Small MySQL "max_allowed_packet"'))
        ->setMessage($message);
    }
  }

}
