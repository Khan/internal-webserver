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
    return self::GROUP_ADMIN;
  }

  public function canUninstall() {
    return false;
  }

  public function getRoutes() {
    return array(
      '/people/' => array(
        '' => 'PhabricatorPeopleListController',
        'logs/' => 'PhabricatorPeopleLogsController',
        'edit/(?:(?P<id>[1-9]\d*)/(?:(?P<view>\w+)/)?)?'
          => 'PhabricatorPeopleEditController',
        'ldap/' => 'PhabricatorPeopleLdapController',
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

      $item = new PhabricatorMenuItemView();
      $item->setName($user->getUsername());
      $item->setHref('/p/'.$user->getUsername().'/');
      $item->setSortOrder(0.0);
      $item->addClass('phabricator-core-menu-item-profile');

      $classes = array(
        'phabricator-core-menu-icon',
        'phabricator-core-menu-profile-image',
      );

      $item->appendChild(
        phutil_render_tag(
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
