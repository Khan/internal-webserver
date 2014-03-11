<?php

final class DivinerMainController extends DivinerController {

  public function shouldAllowPublic() {
    return true;
  }

  public function processRequest() {
    $request = $this->getRequest();
    $viewer = $request->getUser();

    $books = id(new DivinerBookQuery())
      ->setViewer($viewer)
      ->execute();

    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addTextCrumb(pht('Books'));

    $search_icon = id(new PHUIIconView())
      ->setSpriteIcon('search')
      ->setSpriteSheet(PHUIIconView::SPRITE_ICONS);

    $query_button = id(new PHUIButtonView())
      ->setTag('a')
      ->setHref($this->getApplicationURI('query/'))
      ->setText(pht('Advanced Search'))
      ->setIcon($search_icon);

    $header = id(new PHUIHeaderView())
      ->setHeader(pht('Documentation Books'))
      ->addActionLink($query_button);

    $document = new PHUIDocumentView();
    $document->setHeader($header);
    $document->setFontKit(PHUIDocumentView::FONT_SOURCE_SANS);

    if ($books) {
      $books = msort($books, 'getTitle');
      $list = array();
      foreach ($books as $book) {
        $item = id(new DivinerBookItemView())
          ->setTitle($book->getTitle())
          ->setHref('/book/'.$book->getName().'/')
          ->setSubtitle($book->getPreface());
        $list[] = $item;
      }
      $list = id(new PHUIBoxView())
        ->addPadding(PHUI::PADDING_LARGE_LEFT)
        ->addPadding(PHUI::PADDING_LARGE_RIGHT)
        ->addPadding(PHUI::PADDING_SMALL_TOP)
        ->addPadding(PHUI::PADDING_SMALL_BOTTOM)
        ->appendChild($list);

      $document->appendChild($list);

    } else {
      $text = pht(
        "(NOTE) **Looking for Phabricator documentation?** If you're looking ".
        "for help and information about Phabricator, you can ".
        "[[ https://secure.phabricator.com/diviner/ | browse the public ".
        "Phabricator documentation ]] on the live site.\n\n".
        "Diviner is the documentation generator used to build the Phabricator ".
        "documentation.\n\n".
        "You haven't generated any Diviner documentation books yet, so ".
        "there's nothing to show here. If you'd like to generate your own ".
        "local copy of the Phabricator documentation and have it appear ".
        "here, run this command:\n\n".
        "  phabricator/ $ ./bin/diviner generate\n\n".
        "Right now, Diviner isn't very useful for generating documentation ".
        "for projects other than Phabricator. If you're interested in using ".
        "it in your own projects, leave feedback for us on ".
        "[[ https://secure.phabricator.com/T4558 | T4558 ]].");

      $text = PhabricatorMarkupEngine::renderOneObject(
        id(new PhabricatorMarkupOneOff())->setContent($text),
        'default',
        $viewer);

      $document->appendChild($text);
    }

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $document,
      ),
      array(
        'title' => pht('Documentation Books'),
        'device' => true,
        'fonts' => true,
      ));
  }
}
