<?php

abstract class DifferentialController extends PhabricatorController {

  protected function allowsAnonymousAccess() {
    return PhabricatorEnv::getEnvConfig('differential.anonymous-access');
  }

  public function buildApplicationCrumbs() {
    $crumbs = parent::buildApplicationCrumbs();

    $crumbs->addAction(
      id(new PHUIListItemView())
        ->setHref($this->getApplicationURI('/diff/create/'))
        ->setName(pht('Create Diff'))
        ->setIcon('create'));

    return $crumbs;
  }

  public function buildSideNavView($for_app = false) {
    $viewer = $this->getRequest()->getUser();

    $nav = new AphrontSideNavFilterView();
    $nav->setBaseURI(new PhutilURI($this->getApplicationURI()));

    id(new DifferentialRevisionSearchEngine())
      ->setViewer($viewer)
      ->addNavigationItems($nav->getMenu());

    $nav->selectFilter(null);

    return $nav;
  }

  public function buildApplicationMenu() {
    return $this->buildSideNavView(true)->getMenu();
  }

}
