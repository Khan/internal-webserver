<?php

/**
 * @group conduit
 */
abstract class PhabricatorConduitDAO extends PhabricatorLiskDAO {

  public function getApplicationName() {
    return 'conduit';
  }

}
