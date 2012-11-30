<?php

final class PhabricatorApplicationDrydock extends PhabricatorApplication {

  public function getBaseURI() {
    return '/drydock/';
  }

  public function getShortDescription() {
    return 'Allocate Software Resources';
  }

  public function getAutospriteName() {
    return 'drydock';
  }

  public function getTitleGlyph() {
    return "\xE2\x98\x82";
  }

  public function getFlavorText() {
    return pht('A nautical adventure.');
  }

  public function getApplicationGroup() {
    return self::GROUP_UTILITIES;
  }

  public function getRoutes() {
    return array(
      '/drydock/' => array(
        '' => 'DrydockResourceListController',
        'resource/' => array(
          '' => 'DrydockResourceListController',
          '(?P<id>[1-9]\d*)/' => 'DrydockResourceViewController',
          '(?P<id>[1-9]\d*)/close/' => 'DrydockResourceCloseController',
        ),
        'lease/' => array(
          '' => 'DrydockLeaseListController',
          '(?P<id>[1-9]\d*)/' => 'DrydockLeaseViewController',
        ),
        'log/' => 'DrydockLogController',
      ),
    );
  }

}
