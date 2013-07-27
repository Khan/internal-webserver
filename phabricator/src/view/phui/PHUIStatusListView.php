<?php

final class PHUIStatusListView extends AphrontTagView {

  private $items;

  public function addItem(PHUIStatusItemView $item) {
    $this->items[] = $item;
  }

  protected function canAppendChild() {
    return false;
  }

  public function getTagName() {
    return 'table';
  }

  protected function getTagAttributes() {
    require_celerity_resource('phui-status-list-view-css');

    $classes = array();
    $classes[] = 'phui-status-list-view';

    return array(
      'class' => implode(' ', $classes),
    );
  }

  protected function getTagContent() {
    return $this->items;
  }
}
