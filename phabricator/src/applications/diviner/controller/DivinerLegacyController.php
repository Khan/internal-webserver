<?php

final class DivinerLegacyController extends DivinerController {

  public function processRequest() {

    // TODO: Temporary implementation until Diviner is up and running inside
    // Phabricator.

    $links = array(
      'http://www.phabricator.com/docs/phabricator/' => array(
        'name'    => 'Phabricator Ducks',
        'flavor'  => 'Oops, that should say "Docs".',
      ),
      'http://www.phabricator.com/docs/arcanist/' => array(
        'name'    => 'Arcanist Docs',
        'flavor'  => 'Words have never been so finely crafted.',
      ),
      'http://www.phabricator.com/docs/libphutil/' => array(
        'name'    => 'libphutil Docs',
        'flavor'  => 'Soothing prose; seductive poetry.',
      ),
      'http://www.phabricator.com/docs/javelin/' => array(
        'name'    => 'Javelin Docs',
        'flavor'  => 'O, what noble scribe hath penned these words?',
      ),
    );

    $request = $this->getRequest();
    $viewer = $request->getUser();

    $list = id(new PhabricatorObjectItemListView())
      ->setUser($viewer);

    foreach ($links as $href => $link) {
      $item = id(new PhabricatorObjectItemView())
        ->setHref($href)
        ->setHeader($link['name'])
        ->addAttribute($link['flavor']);

      $list->addItem($item);
    }

    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addCrumb(
      id(new PhabricatorCrumbView())
        ->setName(pht('Documentation')));

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $list,
      ),
      array(
        'title' => pht('Documentation'),
        'dust' => true,
        'device' => true,
      ));
  }
}
