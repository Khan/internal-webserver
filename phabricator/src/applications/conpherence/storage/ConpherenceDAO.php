<?php

/**
 * @group conpherence
 */
abstract class ConpherenceDAO extends PhabricatorLiskDAO {

  public function getApplicationName() {
    return 'conpherence';
  }

}
