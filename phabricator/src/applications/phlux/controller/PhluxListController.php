<?php

final class PhluxListController extends PhluxController {

  public function processRequest() {
    $request = $this->getRequest();
    $user = $request->getUser();

    $pager = new AphrontCursorPagerView();
    $pager->readFromRequest($request);
    $query = id(new PhluxVariableQuery())
      ->setViewer($user);

    $vars = $query->executeWithCursorPager($pager);

    $view = new PhabricatorObjectItemListView();
    foreach ($vars as $var) {
      $key = $var->getVariableKey();

      $item = new PhabricatorObjectItemView();
      $item->setHeader($key);
      $item->setHref($this->getApplicationURI('/view/'.$key.'/'));
      $item->addIcon(
        'none',
        phabricator_datetime($var->getDateModified(), $user));

      $view->addItem($item);
    }

    $crumbs = $this->buildApplicationCrumbs();

    $title = pht('Variable List');

    $crumbs->addCrumb(
      id(new PhabricatorCrumbView())
        ->setName($title)
        ->setHref($this->getApplicationURI()));

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $view,
        $pager,
      ),
      array(
        'title'  => $title,
        'device' => true,
      ));
  }

}
