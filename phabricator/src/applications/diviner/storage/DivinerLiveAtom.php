<?php

final class DivinerLiveAtom extends DivinerDAO {

  protected $symbolPHID;
  protected $content;
  protected $atomData;

  public function getConfiguration() {
    return array(
      self::CONFIG_TIMESTAMPS => false,
      self::CONFIG_SERIALIZATION => array(
        'content'  => self::SERIALIZATION_JSON,
        'atomData' => self::SERIALIZATION_JSON,
      ),
    ) + parent::getConfiguration();
  }

}
