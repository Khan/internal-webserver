<?php

/**
 * @group repository
 */
final class PhabricatorApplicationRepositories extends PhabricatorApplication {

  public function getBaseURI() {
    return '/repository/';
  }

  public function getIconName() {
    return 'repositories';
  }

  public function getShortDescription() {
    return 'Track Repositories';
  }

  public function getTitleGlyph() {
    return "rX";
  }

  public function getApplicationGroup() {
    return self::GROUP_ADMIN;
  }

  public function getRoutes() {
    return array(
      '/repository/' => array(
        ''                     => 'PhabricatorRepositoryListController',
        'project/edit/(?P<id>[1-9]\d*)/' =>
          'PhabricatorRepositoryArcanistProjectEditController',
        'project/delete/(?P<id>[1-9]\d*)/' =>
          'PhabricatorRepositoryArcanistProjectDeleteController',
      ),
    );
  }

}
