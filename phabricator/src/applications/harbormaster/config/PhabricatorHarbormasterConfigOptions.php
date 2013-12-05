<?php

final class PhabricatorHarbormasterConfigOptions
  extends PhabricatorApplicationConfigOptions {

  public function getName() {
    return pht('Harbormaster');
  }

  public function getDescription() {
    return pht('Configure Harbormaster build engine.');
  }

  public function getOptions() {
    return array();
  }

}
