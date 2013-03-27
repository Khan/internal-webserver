<?php

final class PhabricatorFlagListController extends PhabricatorFlagController {

  public function processRequest() {
    $request = $this->getRequest();
    $user = $request->getUser();

    $nav = new AphrontSideNavFilterView();

    $filter_form = new AphrontFormView();
    $filter_form->setUser($user);
    $filter_form->appendChild(
      id(new AphrontFormToggleButtonsControl())
        ->setName('o')
        ->setLabel(pht('Sort Order'))
        ->setBaseURI($request->getRequestURI(), 'o')
        ->setValue($request->getStr('o', 'n'))
        ->setButtons(
          array(
            'n'   => pht('Date'),
            'c'   => pht('Color'),
            'o'   => pht('Object Type'),
            'r'   => pht('Reason'),
          )));

    $filter = new AphrontListFilterView();
    $filter->appendChild($filter_form);

    $query = new PhabricatorFlagQuery();
    $query->withOwnerPHIDs(array($user->getPHID()));
    $query->setViewer($user);
    $query->needHandles(true);

    switch ($request->getStr('o', 'n')) {
      case 'n':
        $order = PhabricatorFlagQuery::ORDER_ID;
        break;
      case 'c':
        $order = PhabricatorFlagQuery::ORDER_COLOR;
        break;
      case 'o':
        $order = PhabricatorFlagQuery::ORDER_OBJECT;
        break;
      case 'r':
        $order = PhabricatorFlagQuery::ORDER_REASON;
        break;
      default:
        throw new Exception("Unknown order!");
    }
    $query->withOrder($order);

    $flags = $query->execute();

    $view = new PhabricatorFlagListView();
    $view->setFlags($flags);
    $view->setUser($user);

    $header = new PhabricatorHeaderView();
    $header->setHeader(pht('Flags'));

    $nav->appendChild($header);
    $nav->appendChild($filter);
    $nav->appendChild($view);

    return $this->buildApplicationPage(
      $nav,
      array(
        'title' => pht('Flags'),
        'dust'  => true,
      ));
  }

}
