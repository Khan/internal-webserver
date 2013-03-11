<?php

final class PhabricatorObjectItemListExample extends PhabricatorUIExample {

  public function getName() {
    return 'Object Item List';
  }

  public function getDescription() {
    return hsprintf(
      'Use <tt>PhabricatorObjectItemListView</tt> to render lists of objects.');
  }

  public function renderExample() {
    $request = $this->getRequest();
    $user = $request->getUser();

    $handle = PhabricatorObjectHandleData::loadOneHandle(
      $user->getPHID(),
      $user);

    $out = array();

    $head = id(new PhabricatorHeaderView())
      ->setHeader(pht('Basic List'));

    $list = new PhabricatorObjectItemListView();

    $list->addItem(
      id(new PhabricatorObjectItemView())
        ->setHeader(pht('Apple'))
        ->setHref('#'));

    $list->addItem(
      id(new PhabricatorObjectItemView())
        ->setHeader(pht('Banana'))
        ->setHref('#'));

    $list->addItem(
      id(new PhabricatorObjectItemView())
        ->setHeader(pht('Cherry'))
        ->setHref('#'));

    $out[] = array($head, $list);


    $head = id(new PhabricatorHeaderView())
      ->setHeader(pht('Empty List'));
    $list = new PhabricatorObjectItemListView();

    $list->setNoDataString(pht('This list is empty.'));

    $out[] = array($head, $list);


    $head = id(new PhabricatorHeaderView())
      ->setHeader(pht('Stacked List'));
    $list = new PhabricatorObjectItemListView();
    $list->setStackable(true);

    $list->addItem(
      id(new PhabricatorObjectItemView())
        ->setHeader(pht('Monday'))
        ->setHref('#'));
    $list->addItem(
      id(new PhabricatorObjectItemView())
        ->setHeader(pht('Tuesday'))
        ->setHref('#'));
    $list->addItem(
      id(new PhabricatorObjectItemView())
        ->setHeader(pht('Wednesday'))
        ->setHref('#'));
    $list->addItem(
      id(new PhabricatorObjectItemView())
        ->setHeader(pht('Thursday'))
        ->setHref('#'));

    $out[] = array($head, $list);


    $head = id(new PhabricatorHeaderView())
      ->setHeader(pht('Card List'));
    $list = new PhabricatorObjectItemListView();
    $list->setCards(true);

    $list->addItem(
      id(new PhabricatorObjectItemView())
        ->setHeader(pht('Business Card')));
    $list->addItem(
      id(new PhabricatorObjectItemView())
        ->setHeader(pht('Playing Card')));
    $list->addItem(
      id(new PhabricatorObjectItemView())
        ->setHeader(pht('House of Cards')));
    $list->addItem(
      id(new PhabricatorObjectItemView())
        ->setHeader(pht('Cardigan')));
    $list->addItem(
      id(new PhabricatorObjectItemView())
        ->setHeader(pht('Cardamom'))
        ->addFootIcon('highlight-white', 'Spice'));
    $list->addItem(
      id(new PhabricatorObjectItemView())
        ->setHeader(pht(
          'The human cardiovascular system includes the heart, lungs, and '.
          'some other parts; most of these parts are pretty squishy'))
        ->addFootIcon('search-white', pht('Respiration!'))
        ->addHandleIcon($handle, pht('You have a cardiovascular system!')));


    $out[] = array($head, $list);


    $head = id(new PhabricatorHeaderView())
      ->setHeader(pht('Extras'));

    $list = new PhabricatorObjectItemListView();

    $list->addItem(
      id(new PhabricatorObjectItemView())
        ->setHeader(pht('Ace of Hearts'))
        ->setHref('#')
        ->addAttribute(pht('Suit: Hearts'))
        ->addAttribute(pht('Rank: Ace'))
        ->addIcon('love', pht('Ace'))
        ->addIcon('love-grey', pht('Hearts'))
        ->addFootIcon('blame-white', pht('Ace'))
        ->addFootIcon('love-white', pht('Heart'))
        ->addHandleIcon($handle, pht('You hold all the cards.'))
        ->addHandleIcon($handle, pht('You make all the rules.')));

    $out[] = array($head, $list);


    $head = id(new PhabricatorHeaderView())
      ->setHeader(pht('Effects'));

    $list = new PhabricatorObjectItemListView();

    $list->addItem(
      id(new PhabricatorObjectItemView())
        ->setHeader(pht('Normal'))
        ->setHref('#'));

    $list->addItem(
      id(new PhabricatorObjectItemView())
        ->setHeader(pht('Highlighted'))
        ->setEffect('highlighted')
        ->setHref('#'));

    $list->addItem(
      id(new PhabricatorObjectItemView())
        ->setHeader(pht('Selected'))
        ->setEffect('selected')
        ->setHref('#'));

    $out[] = array($head, $list);


    $head = id(new PhabricatorHeaderView())
      ->setHeader(pht('Effects'));

    $list = new PhabricatorObjectItemListView();

    $bar_colors = array(
      null      => pht('None'),
      'red'     => pht('Red'),
      'orange'  => pht('Orange'),
      'yellow'  => pht('Yellow'),
      'green'   => pht('Green'),
      'sky'     => pht('Sky'),
      'blue'    => pht('Blue'),
      'indigo'  => pht('Indigo'),
      'violet'  => pht('Violet'),
      'grey'    => pht('Grey'),
      'black'   => pht('Black'),
    );

    foreach ($bar_colors as $bar_color => $bar_label) {
      $list->addItem(
        id(new PhabricatorObjectItemView())
          ->setHeader($bar_label)
          ->setBarColor($bar_color));
    }

    $out[] = array($head, $list);

    return $out;
  }
}
