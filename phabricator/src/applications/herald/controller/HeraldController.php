<?php

abstract class HeraldController extends PhabricatorController {

  public function buildStandardPageResponse($view, array $data) {
    $page = $this->buildStandardPageView();

    $page->setApplicationName(pht('Herald'));
    $page->setBaseURI('/herald/');
    $page->setTitle(idx($data, 'title'));
    $page->setGlyph("\xE2\x98\xBF");

    $page->appendChild($view);

    $response = new AphrontWebpageResponse();
    return $response->setContent($page->render());
  }

  public function buildApplicationMenu() {
    return $this->buildSideNavView(true)->getMenu();
  }

  public function buildApplicationCrumbs() {
    $crumbs = parent::buildApplicationCrumbs();

    $can_create = $this->hasApplicationCapability(
      PhabricatorApplicationHerald::CAN_CREATE_RULE);

    $crumbs->addAction(
      id(new PHUIListItemView())
        ->setName(pht('Create Herald Rule'))
        ->setHref($this->getApplicationURI('new/'))
        ->setIcon('create')
        ->setDisabled(!$can_create));

    return $crumbs;
  }

  public function buildSideNavView($for_app = false) {
    $user = $this->getRequest()->getUser();

    $nav = new AphrontSideNavFilterView();
    $nav->setBaseURI(new PhutilURI($this->getApplicationURI()));

    if ($for_app) {
      $nav->addFilter('create', pht('Create Rule'));
    }

    id(new HeraldRuleSearchEngine())
      ->setViewer($user)
      ->addNavigationItems($nav->getMenu());

    $nav
      ->addLabel(pht('Utilities'))
      ->addFilter('test',       pht('Test Console'))
      ->addFilter('transcript', pht('Transcripts'))
      ->addFilter('history',    pht('Edit Log'));

    $nav->selectFilter(null);

    return $nav;
  }

}
