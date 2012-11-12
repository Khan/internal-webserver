<?php

final class PhabricatorWorkerTaskData extends PhabricatorWorkerDAO {

  protected $data;

  public function getConfiguration() {
    return array(
      self::CONFIG_TIMESTAMPS => false,
      self::CONFIG_SERIALIZATION => array(
        'data' => self::SERIALIZATION_JSON,
      ),
    ) + parent::getConfiguration();
  }

}
