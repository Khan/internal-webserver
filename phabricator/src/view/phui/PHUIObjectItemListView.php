<?php

final class PHUIObjectItemListView extends AphrontTagView {

  private $header;
  private $items;
  private $pager;
  private $stackable;
  private $cards;
  private $noDataString;
  private $flush;
  private $plain;

  public function setFlush($flush) {
    $this->flush = $flush;
    return $this;
  }

  public function setPlain($plain) {
    $this->plain = $plain;
    return $this;
  }

  public function setHeader($header) {
    $this->header = $header;
    return $this;
  }

  public function setPager($pager) {
    $this->pager = $pager;
    return $this;
  }

  public function setNoDataString($no_data_string) {
    $this->noDataString = $no_data_string;
    return $this;
  }

  public function addItem(PHUIObjectItemView $item) {
    $this->items[] = $item;
    return $this;
  }

  public function setStackable($stackable) {
    $this->stackable = $stackable;
    return $this;
  }

  public function setCards($cards) {
    $this->cards = $cards;
    return $this;
  }

  protected function getTagName() {
    return 'ul';
  }

  protected function getTagAttributes() {
    $classes = array();

    $classes[] = 'phui-object-item-list-view';
    if ($this->stackable) {
      $classes[] = 'phui-object-list-stackable';
    }
    if ($this->cards) {
      $classes[] = 'phui-object-list-cards';
    }
    if ($this->flush) {
      $classes[] = 'phui-object-list-flush';
    }
    if ($this->plain) {
      $classes[] = 'phui-object-list-plain';
    }

    return array(
      'class' => $classes,
    );
  }

  protected function getTagContent() {
    require_celerity_resource('phui-object-item-list-view-css');

    $header = null;
    if (strlen($this->header)) {
      $header = phutil_tag(
        'h1',
        array(
          'class' => 'phui-object-item-list-header',
        ),
        $this->header);
    }

    if ($this->items) {
      $items = $this->items;
    } else {
      $string = nonempty($this->noDataString, pht('No data.'));
      $items = id(new AphrontErrorView())
        ->setSeverity(AphrontErrorView::SEVERITY_NODATA)
        ->appendChild($string);
    }

    $pager = null;
    if ($this->pager) {
      $pager = $this->pager;
    }

    return array(
      $header,
      $items,
      $pager,
      $this->renderChildren(),
    );
  }

}
