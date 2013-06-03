<?php

abstract class PhabricatorPeopleController extends PhabricatorController {

  public function shouldRequireAdmin() {
    return true;
  }

  public function buildSideNavView() {
    $nav = new AphrontSideNavFilterView();
    $nav->setBaseURI(new PhutilURI($this->getApplicationURI()));

    $viewer = $this->getRequest()->getUser();

    id(new PhabricatorPeopleSearchEngine())
      ->setViewer($viewer)
      ->addNavigationItems($nav->getMenu());

    if ($viewer->getIsAdmin()) {
      $nav->addLabel(pht('User Administration'));
      if (PhabricatorEnv::getEnvConfig('ldap.auth-enabled') === true) {
        $nav->addFilter('ldap', pht('Import from LDAP'));
      }

      $nav->addFilter('logs', pht('Activity Logs'));
    }

    return $nav;
  }

  public function buildApplicationMenu() {
    return $this->buildSideNavView()->getMenu();
  }

  public function buildApplicationCrumbs() {
    $crumbs = parent::buildApplicationCrumbs();

    $viewer = $this->getRequest()->getUser();

    if ($viewer->getIsAdmin()) {
      $crumbs->addAction(
        id(new PhabricatorMenuItemView())
          ->setName(pht('Create New User'))
          ->setHref($this->getApplicationURI('edit'))
          ->setIcon('create'));
    }

    return $crumbs;
  }

}
