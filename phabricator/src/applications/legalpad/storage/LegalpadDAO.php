<?php

/**
 * @group legalpad
 */
abstract class LegalpadDAO extends PhabricatorLiskDAO {

  public function getApplicationName() {
    return 'legalpad';
  }

}
