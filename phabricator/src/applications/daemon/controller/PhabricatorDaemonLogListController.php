<?php

final class PhabricatorDaemonLogListController
  extends PhabricatorDaemonController {

  public function processRequest() {
    $request = $this->getRequest();
    $viewer = $request->getUser();

    $pager = new AphrontCursorPagerView();
    $pager->readFromRequest($request);

    $logs = id(new PhabricatorDaemonLogQuery())
      ->setViewer($viewer)
      ->executeWithCursorPager($pager);

    $daemon_table = new PhabricatorDaemonLogListView();
    $daemon_table->setUser($request->getUser());
    $daemon_table->setDaemonLogs($logs);

    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addCrumb(
      id(new PhabricatorCrumbView())
        ->setName(pht('All Daemons')));

    $nav = $this->buildSideNavView();
    $nav->selectFilter('log');
    $nav->setCrumbs($crumbs);
    $nav->appendChild($daemon_table);
    $nav->appendChild($pager);

    return $this->buildApplicationPage(
      $nav,
      array(
        'title' => pht('All Daemons'),
        'device' => true,
        'dust' => true,
      ));
  }

}
