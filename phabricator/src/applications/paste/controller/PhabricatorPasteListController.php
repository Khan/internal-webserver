<?php

final class PhabricatorPasteListController extends PhabricatorPasteController {

  public function shouldRequireLogin() {
    return false;
  }

  private $filter;

  public function willProcessRequest(array $data) {
    $this->filter = idx($data, 'filter');
  }

  public function processRequest() {
    $request = $this->getRequest();
    $user = $request->getUser();

    $query = id(new PhabricatorPasteQuery())
      ->setViewer($user)
      ->needContent(true);

    $nav = $this->buildSideNavView($this->filter);
    $filter = $nav->getSelectedFilter();

    switch ($filter) {
      case 'my':
        $query->withAuthorPHIDs(array($user->getPHID()));
        $title = pht('My Pastes');
        $nodata = pht("You haven't created any Pastes yet.");
        break;
      case 'all':
        $title = pht('All Pastes');
        $nodata = pht("There are no Pastes yet.");
        break;
    }

    $pager = new AphrontCursorPagerView();
    $pager->readFromRequest($request);
    $pastes = $query->executeWithCursorPager($pager);

    $list = $this->buildPasteList($pastes);
    $list->setPager($pager);
    $list->setNoDataString($nodata);

    $nav->appendChild(
      array(
        $list,
      ));

    $crumbs = $this
      ->buildApplicationCrumbs($nav)
      ->addCrumb(
        id(new PhabricatorCrumbView())
          ->setName($title)
          ->setHref($this->getApplicationURI('filter/'.$filter.'/')));

    $nav->setCrumbs($crumbs);

    return $this->buildApplicationPage(
      $nav,
      array(
        'title' => $title,
        'device' => true,
        'dust' => true,
      ));
  }

  private function buildPasteList(array $pastes) {
    assert_instances_of($pastes, 'PhabricatorPaste');

    $user = $this->getRequest()->getUser();

    $this->loadHandles(mpull($pastes, 'getAuthorPHID'));

    $lang_map = PhabricatorEnv::getEnvConfig('pygments.dropdown-choices');

    $list = new PhabricatorObjectItemListView();
    $list->setUser($user);
    foreach ($pastes as $paste) {
      $created = phabricator_date($paste->getDateCreated(), $user);
      $author = $this->getHandle($paste->getAuthorPHID())->renderLink();
      $source_code = $this->buildSourceCodeView($paste, 5)->render();

      $source_code = phutil_tag(
        'div',
        array(
          'class' => 'phabricator-source-code-summary',
        ),
        $source_code);

      $line_count = count(explode("\n", $paste->getContent()));
      $line_count = pht(
        '%s Line(s)',
        new PhutilNumber($line_count));

      $title = nonempty($paste->getTitle(), pht('(An Untitled Masterwork)'));

      $item = id(new PhabricatorObjectItemView())
        ->setObjectName('P'.$paste->getID())
        ->setHeader($title)
        ->setHref('/P'.$paste->getID())
        ->setObject($paste)
        ->addAttribute(pht('Created %s by %s', $created, $author))
        ->addIcon('none', $line_count)
        ->appendChild($source_code);

      $lang_name = $paste->getLanguage();
      if ($lang_name) {
        $lang_name = idx($lang_map, $lang_name, $lang_name);
        $item->addIcon('none', $lang_name);
      }

      $list->addItem($item);
    }

    return $list;
  }

}
