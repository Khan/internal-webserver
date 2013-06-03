<?php

final class DivinerAtomListController extends DivinerController
  implements PhabricatorApplicationSearchResultsControllerInterface {

  private $key;

  public function shouldAllowPublic() {
    return true;
  }

  public function willProcessRequest(array $data) {
    $this->key = idx($data, 'key', 'all');
  }

  public function processRequest() {
    $request = $this->getRequest();
    $controller = id(new PhabricatorApplicationSearchController($request))
      ->setQueryKey($this->key)
      ->setSearchEngine(new DivinerAtomSearchEngine())
      ->setNavigation($this->buildSideNavView());

    return $this->delegateToController($controller);
  }

  public function renderResultsList(array $symbols) {
    assert_instances_of($symbols, 'DivinerLiveSymbol');

    $request = $this->getRequest();
    $user = $request->getUser();

    $list = id(new PhabricatorObjectItemListView())
      ->setUser($user);

    foreach ($symbols as $symbol) {
      $item = id(new PhabricatorObjectItemView())
        ->setHeader($symbol->getName())
        ->setHref($symbol->getURI())
        ->addIcon('none', $symbol->getType());

      $list->addItem($item);
    }

    return $list;
  }

}
