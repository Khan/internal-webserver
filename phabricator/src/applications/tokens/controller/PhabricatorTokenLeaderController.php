<?php

final class PhabricatorTokenLeaderController
    extends PhabricatorTokenController {

  public function processRequest() {
    $request = $this->getRequest();
    $user = $request->getUser();

    $pager = new AphrontPagerView();
    $pager->setURI($request->getRequestURI(), 'page');
    $pager->setOffset($request->getInt('page'));

    $query = id(new PhabricatorTokenReceiverQuery());
    $objects = $query->setViewer($user)->executeWithOffsetPager($pager);
    $counts = $query->getTokenCounts();

    $handles = array();
    $phids = array();
    if ($counts) {
      $phids = mpull($objects, 'getPHID');
      $handles = id(new PhabricatorHandleQuery())
        ->setViewer($user)
        ->withPHIDs($phids)
        ->execute();
    }

    $list = new PHUIObjectItemListView();
    foreach ($phids as $object) {
      $count = idx($counts, $object, 0);
      $item = id(new PHUIObjectItemView());
      $handle = $handles[$object];

      $item->setHeader($handle->getFullName());
      $item->setHref($handle->getURI());
      $item->addAttribute(pht('Tokens: %s', $count));
      $list->addItem($item);
    }

    $title = pht('Token Leader Board');

    $nav = $this->buildSideNav();
    $nav->setCrumbs(
      $this->buildApplicationCrumbs()
        ->addCrumb(
          id(new PhabricatorCrumbView())
            ->setName($title)));
    $nav->selectFilter('leaders/');

    $nav->appendChild($list);
    $nav->appendChild($pager);

    return $this->buildApplicationPage(
      $nav,
      array(
        'title' => $title,
        'device' => true,
      ));
  }

}
