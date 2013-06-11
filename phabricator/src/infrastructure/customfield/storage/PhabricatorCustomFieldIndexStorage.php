<?php

abstract class PhabricatorCustomFieldIndexStorage
  extends PhabricatorLiskDAO {

  protected $objectPHID;
  protected $indexKey;
  protected $indexValue;

  public function getConfiguration() {
    return array(
      self::CONFIG_TIMESTAMPS => false,
    ) + parent::getConfiguration();
  }

  abstract public function formatForInsert(AphrontDatabaseConnection $conn);

}
