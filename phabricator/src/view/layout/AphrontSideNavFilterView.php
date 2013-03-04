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
  private $collapsed = false;
  private $active;
  private $menu;
  private $crumbs;
  private $classes = array();
  private $menuID;

  public function setMenuID($menu_id) {
    $this->menuID = $menu_id;
    return $this;
  }
  public function getMenuID() {
    return $this->menuID;
  }

  public function __construct() {
    $this->menu = new PhabricatorMenuView();
  }

  public function addClass($class) {
    $this->classes[] = $class;
    return $this;
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

  public function setCollapsed($collapsed) {
    $this->collapsed = $collapsed;
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

  public function addFilter($key, $name, $uri = null) {
    return $this->addThing(
      $key, $name, $uri, PhabricatorMenuItemView::TYPE_LINK);
  }

  public function addButton($key, $name, $uri = null) {
    return $this->addThing(
      $key, $name, $uri, PhabricatorMenuItemView::TYPE_BUTTON);
  }

  private function addThing(
    $key,
    $name,
    $uri = null,
    $type) {

    $item = id(new PhabricatorMenuItemView())
      ->setName($name)
      ->setType($type);

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
    $this->menu->addMenuItem(
      id(new PhabricatorMenuItemView())
        ->setType(PhabricatorMenuItemView::TYPE_CUSTOM)
        ->appendChild($block));
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
        throw new Exception(pht("Call setBaseURI() before render()!"));
      }
      if ($this->selectedFilter === false) {
        throw new Exception(pht("Call selectFilter() before render()!"));
      }
    }

    if ($this->selectedFilter !== null) {
      $selected_item = $this->menu->getItem($this->selectedFilter);
      if ($selected_item) {
        $selected_item->addClass('phabricator-menu-item-selected');
      }
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
      $flex_bar = phutil_tag(
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

      if (!$this->collapsed) {
        $nav_classes[] = 'has-local-nav';
      }

      $menu_background = phutil_tag(
        'div',
        array(
          'class' => 'phabricator-nav-column-background',
          'id'    => $background_id,
        ),
        '');

      $local_menu = $this->renderSingleView(
        array(
          $menu_background,
          phutil_tag(
            'div',
            array(
              'class' => 'phabricator-nav-local phabricator-side-menu',
              'id'    => $local_id,
            ),
            self::renderSingleView($this->menu->setID($this->getMenuID()))),
        ));
    }

    $crumbs = null;
    if ($this->crumbs) {
      $crumbs = $this->crumbs->render();
      $nav_classes[] = 'has-crumbs';
    }

    if ($this->flexible) {
      if (!$this->collapsed) {
        $nav_classes[] = 'has-drag-nav';
      }

      Javelin::initBehavior(
        'phabricator-nav',
        array(
          'mainID'        => $main_id,
          'localID'       => $local_id,
          'dragID'        => $drag_id,
          'contentID'     => $content_id,
          'backgroundID'  => $background_id,
          'collapsed'     => $this->collapsed,
        ));

      if ($this->active) {
        Javelin::initBehavior(
          'phabricator-active-nav',
          array(
            'localID' => $local_id,
          ));
      }
    }

    $nav_classes = array_merge($nav_classes, $this->classes);

    return phutil_tag(
      'div',
      array(
        'class' => implode(' ', $nav_classes),
        'id'    => $main_id,
      ),
      array(
        $local_menu,
        $flex_bar,
        phutil_tag(
          'div',
          array(
            'class' => 'phabricator-nav-content',
            'id' => $content_id,
          ),
          array(
            $crumbs,
            phutil_implode_html('', $this->renderChildren()),
          ))
      ));
  }

}
