<?php

abstract class PhabricatorDaemonController extends PhabricatorController {

  protected function buildSideNavView() {
    $nav = new AphrontSideNavFilterView();
    $nav->setBaseURI(new PhutilURI($this->getApplicationURI()));

    $nav->addLabel(pht('Daemons'));
    $nav->addFilter('/', pht('Console'));
    $nav->addFilter('log', pht('All Daemons'));
    $nav->addFilter('log/combined', pht('Combined Log'));

    return $nav;
  }

}
