<?php

final class PhabricatorObjectItemListView extends AphrontView {

  private $header;
  private $items;
  private $pager;
  private $stackable;
  private $noDataString;

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

  public function addItem(PhabricatorObjectItemView $item) {
    $this->items[] = $item;
    return $this;
  }

  public function setStackable() {
    $this->stackable = true;
    return $this;
  }

  public function render() {
    require_celerity_resource('phabricator-object-item-list-view-css');

    $classes = array();
    $header = null;
    if (strlen($this->header)) {
      $header = phutil_tag(
        'h1',
        array(
          'class' => 'phabricator-object-item-list-header',
        ),
        $this->header);
    }

    if ($this->items) {
      $items = $this->renderSingleView($this->items);
    } else {
      $string = nonempty($this->noDataString, pht('No data.'));
      $items = id(new AphrontErrorView())
        ->setSeverity(AphrontErrorView::SEVERITY_NODATA)
        ->appendChild($string);
    }

    $pager = null;
    if ($this->pager) {
      $pager = $this->renderSingleView($this->pager);
    }

    $classes[] = 'phabricator-object-item-list-view';
    if ($this->stackable) {
      $classes[] = 'phabricator-object-list-stackable';
    }

    return phutil_tag(
      'ul',
      array(
        'class' => implode(' ', $classes),
      ),
      $this->renderSingleView(
        array(
          $header,
          $items,
          $pager,
        )));
  }

}
