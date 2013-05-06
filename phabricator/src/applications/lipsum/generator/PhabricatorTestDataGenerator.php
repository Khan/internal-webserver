<?php

abstract class PhabricatorTestDataGenerator {

  public function generate() {
    return;
  }

  public function loadOneRandom($classname) {
    try {
      return newv($classname, array())
        ->loadOneWhere("1 = 1 ORDER BY RAND() LIMIT 1");
    } catch (PhutilMissingSymbolException $ex) {
      throw new PhutilMissingSymbolException(
        "Unable to load symbol ".$classname.": this class does not exit.");
    }
  }


  public function loadPhabrictorUserPHID() {
    return $this->loadOneRandom("PhabricatorUser")->getPHID();
  }

  public function loadPhabrictorUser() {
    return $this->loadOneRandom("PhabricatorUser");
  }

}
