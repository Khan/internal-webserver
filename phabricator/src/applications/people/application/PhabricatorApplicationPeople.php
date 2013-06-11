<?php

final class PhabricatorApplicationPeople extends PhabricatorApplication {

  public function getShortDescription() {
    return 'User Accounts';
  }

  public function getBaseURI() {
    return '/people/';
  }

  public function getTitleGlyph() {
    return "\xE2\x99\x9F";
  }

  public function getIconName() {
    return 'people';
  }

  public function getFlavorText() {
    return pht('Sort of a social utility.');
  }

  public function getApplicationGroup() {
    return self::GROUP_ORGANIZATION;
  }

  public function canUninstall() {
    return false;
  }

  public function getEventListeners() {
    return array(
      new PhabricatorPeopleHovercardEventListener(),
    );
  }

  public function getRoutes() {
    return array(
      '/people/' => array(
        '(query/(?P<key>[^/]+)/)?' => 'PhabricatorPeopleListController',
        'logs/' => 'PhabricatorPeopleLogsController',
        'edit/(?:(?P<id>[1-9]\d*)/(?:(?P<view>\w+)/)?)?'
          => 'PhabricatorPeopleEditController',
        'ldap/' => 'PhabricatorPeopleLdapController',
        'editprofile/(?P<id>[1-9]\d*)/' =>
          'PhabricatorPeopleProfileEditController',
      ),
      '/p/(?P<username>[\w._-]+)/(?:(?P<page>\w+)/)?'
        => 'PhabricatorPeopleProfileController',
      '/emailverify/(?P<code>[^/]+)/' =>
        'PhabricatorEmailVerificationController',
    );
  }

  public function buildMainMenuItems(
    PhabricatorUser $user,
    PhabricatorController $controller = null) {

    $items = array();

    if ($user->isLoggedIn()) {
      $image = $user->loadProfileImageURI();

      $item = new PHUIListItemView();
      $item->setName($user->getUsername());
      $item->setHref('/p/'.$user->getUsername().'/');
      $item->addClass('core-menu-item');

      $classes = array(
        'phabricator-core-menu-icon',
        'phabricator-core-menu-profile-image',
      );

      $item->appendChild(
        phutil_tag(
          'span',
          array(
            'class' => implode(' ', $classes),
            'style' => 'background-image: url('.$image.')',
          ),
          ''));

      $items[] = $item;
    }

    return $items;
  }

}
