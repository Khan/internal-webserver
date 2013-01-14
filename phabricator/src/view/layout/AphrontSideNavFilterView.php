<?php

/**
 * Provides a navigation sidebar. For example:
 *
 *    $nav = new AphrontSideNavFilterView();
 *    $nav
 *      ->setBaseURI($some_uri)
 *      ->addLabel('Cats')
 *      ->addFilter('meow', 'Meow')
 *      ->addFilter('purr', 'Purr')
 *      ->addLabel('Dogs')
 *      ->addFilter('woof', 'Woof')
 *      ->addFilter('bark', 'Bark');
 *    $valid_filter = $nav->selectFilter($user_selection, $default = 'meow');
 *
 */
final class AphrontSideNavFilterView extends AphrontView {

  private $items = array();
  private $baseURI;
  private $selectedFilter = false;
  private $flexible;
  private $active;
  private $menu;
  private $crumbs;

  public function __construct() {
    $this->menu = new PhabricatorMenuView();
  }

  public static function newFromMenu(PhabricatorMenuView $menu) {
    $object = new AphrontSideNavFilterView();
    $object->setBaseURI(new PhutilURI('/'));
    $object->menu = $menu;
    return $object;
  }

  public function setCrumbs(PhabricatorCrumbsView $crumbs) {
    $this->crumbs = $crumbs;
    return $this;
  }

  public function getCrumbs() {
    return $this->crumbs;
  }

  public function setActive($active) {
    $this->active = $active;
    return $this;
  }

  public function setFlexible($flexible) {
    $this->flexible = $flexible;
    return $this;
  }

  public function getMenuView() {
    return $this->menu;
  }

  public function addMenuItem(PhabricatorMenuItemView $item) {
    $this->menu->addMenuItem($item);
    return $this;
  }

  public function getMenu() {
    return $this->menu;
  }

  public function addFilter(
    $key,
    $name,
    $uri = null) {

    $item = id(new PhabricatorMenuItemView())
      ->setName($name);

    if (strlen($key)) {
      $item->setKey($key);
    }

    if ($uri) {
      $item->setHref($uri);
    } else {
      $href = clone $this->baseURI;
      $href->setPath(rtrim($href->getPath().$key, '/').'/');
      $href = (string)$href;

      $item->setHref($href);
    }

    return $this->addMenuItem($item);
  }

  public function addCustomBlock($block) {
    $this->menu->appendChild($block);
    return $this;
  }

  public function addLabel($name) {
    return $this->addMenuItem(
      id(new PhabricatorMenuItemView())
        ->setType(PhabricatorMenuItemView::TYPE_LABEL)
        ->setName($name));
  }

  public function setBaseURI(PhutilURI $uri) {
    $this->baseURI = $uri;
    return $this;
  }

  public function getBaseURI() {
    return $this->baseURI;
  }

  public function selectFilter($key, $default = null) {
    $this->selectedFilter = $default;
    if ($this->menu->getItem($key) && strlen($key)) {
      $this->selectedFilter = $key;
    }
    return $this->selectedFilter;
  }

  public function getSelectedFilter() {
    return $this->selectedFilter;
  }

  public function render() {
    if ($this->menu->getItems()) {
      if (!$this->baseURI) {
        throw new Exception("Call setBaseURI() before render()!");
      }
      if ($this->selectedFilter === false) {
        throw new Exception("Call selectFilter() before render()!");
      }
    }

    $selected_item = $this->menu->getItem($this->selectedFilter);
    if ($selected_item) {
      $selected_item->addClass('phabricator-menu-item-selected');
    }

    require_celerity_resource('phabricator-side-menu-view-css');

    return $this->renderFlexNav();
  }

  private function renderFlexNav() {

    $user = $this->user;

    require_celerity_resource('phabricator-nav-view-css');

    $nav_classes = array();
    $nav_classes[] = 'phabricator-nav';

    $nav_id = null;
    $drag_id = null;
    $content_id = celerity_generate_unique_node_id();
    $local_id = null;
    $background_id = null;
    $local_menu = null;
    $main_id = celerity_generate_unique_node_id();

    if ($this->flexible) {
      $drag_id = celerity_generate_unique_node_id();
      $flex_bar = phutil_render_tag(
        'div',
        array(
          'class' => 'phabricator-nav-drag',
          'id' => $drag_id,
        ),
        '');
    } else {
      $flex_bar = null;
    }

    $nav_menu = null;
    if ($this->menu->getItems()) {
      $local_id = celerity_generate_unique_node_id();
      $background_id = celerity_generate_unique_node_id();
      $nav_classes[] = 'has-local-nav';

      $menu_background = phutil_render_tag(
        'div',
        array(
          'class' => 'phabricator-nav-column-background',
          'id'    => $background_id,
        ),
        '');

      $local_menu = $menu_background.phutil_render_tag(
        'div',
        array(
          'class' => 'phabricator-nav-local phabricator-side-menu',
          'id'    => $local_id,
        ),
        self::renderSingleView($this->menu));
    }

    $crumbs = null;
    if ($this->crumbs) {
      $crumbs = $this->crumbs->render();
      $nav_classes[] = 'has-crumbs';
    }

    if ($this->flexible) {
      Javelin::initBehavior(
        'phabricator-nav',
        array(
          'mainID'        => $main_id,
          'localID'       => $local_id,
          'dragID'        => $drag_id,
          'contentID'     => $content_id,
          'backgroundID'  => $background_id,
        ));

      if ($this->active) {
        Javelin::initBehavior(
          'phabricator-active-nav',
          array(
            'localID' => $local_id,
          ));
      }
    }

    return $crumbs.phutil_render_tag(
      'div',
      array(
        'class' => implode(' ', $nav_classes),
        'id'    => $main_id,
      ),
      $local_menu.
      $flex_bar.
      phutil_render_tag(
        'div',
        array(
          'class' => 'phabricator-nav-content',
          'id' => $content_id,
        ),
        $this->renderChildren()));
  }

}
