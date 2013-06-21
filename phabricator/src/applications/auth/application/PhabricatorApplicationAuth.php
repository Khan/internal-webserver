<?php

final class PhabricatorApplicationAuth extends PhabricatorApplication {

  public function canUninstall() {
    return false;
  }

  public function getBaseURI() {
    return '/auth/';
  }

  public function getIconName() {
    return 'authentication';
  }

  public function getHelpURI() {
    // NOTE: Although reasonable help exists for this in "Configuring Accounts
    // and Registration", specifying a help URI here means we get the menu
    // item in all the login/link interfaces, which is confusing and not
    // helpful.

    // TODO: Special case this, or split the auth and auth administration
    // applications?

    return null;
  }

  public function buildMainMenuItems(
    PhabricatorUser $user,
    PhabricatorController $controller = null) {

    $items = array();

    if ($user->isLoggedIn()) {
      $item = id(new PHUIListItemView())
        ->addClass('core-menu-item')
        ->setName(pht('Log Out'))
        ->setIcon('power')
        ->setWorkflow(true)
        ->setHref('/logout/')
        ->setSelected(($controller instanceof PhabricatorLogoutController));
      $items[] = $item;
    }

    return $items;
  }

  public function getApplicationGroup() {
    return self::GROUP_ADMIN;
  }

  public function getRoutes() {
    return array(
      '/auth/' => array(
        '' => 'PhabricatorAuthListController',
        'config/' => array(
          'new/' => 'PhabricatorAuthNewController',
          'new/(?P<className>[^/]+)/' => 'PhabricatorAuthEditController',
          'edit/(?P<id>\d+)/' => 'PhabricatorAuthEditController',
          '(?P<action>enable|disable)/(?P<id>\d+)/' =>
            'PhabricatorAuthDisableController',
        ),
        'login/(?P<pkey>[^/]+)/' => 'PhabricatorAuthLoginController',
        'register/(?:(?P<akey>[^/]+)/)?' => 'PhabricatorAuthRegisterController',
        'start/' => 'PhabricatorAuthStartController',
        'validate/' => 'PhabricatorAuthValidateController',
        'unlink/(?P<pkey>[^/]+)/' => 'PhabricatorAuthUnlinkController',
        'link/(?P<pkey>[^/]+)/' => 'PhabricatorAuthLinkController',
        'confirmlink/(?P<akey>[^/]+)/'
          => 'PhabricatorAuthConfirmLinkController',
      ),

      '/oauth/(?P<provider>\w+)/login/'
        => 'PhabricatorAuthOldOAuthRedirectController',

      '/login/' => array(
        '' => 'PhabricatorAuthStartController',
        'email/' => 'PhabricatorEmailLoginController',
        'etoken/(?P<token>\w+)/' => 'PhabricatorEmailTokenController',
        'refresh/' => 'PhabricatorRefreshCSRFController',
        'mustverify/' => 'PhabricatorMustVerifyEmailController',
      ),

      '/logout/' => 'PhabricatorLogoutController',
    );
  }

}
