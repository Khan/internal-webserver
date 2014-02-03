<?php

final class PhabricatorApplicationHome extends PhabricatorApplication {

  public function getBaseURI() {
    return '/';
  }

  public function getShortDescription() {
    return pht('Where the <3 is');
  }

  public function getIconName() {
    return 'home';
  }

  public function getRoutes() {
    return array(
      '/(?:(?P<filter>(?:jump))/)?' => 'PhabricatorHomeMainController',
      '/home/' => array(
        'create/' => 'PhabricatorHomeQuickCreateController',
      ),
    );
  }

  public function shouldAppearInLaunchView() {
    return false;
  }

  public function canUninstall() {
    return false;
  }

  public function getApplicationOrder() {
    return 9;
  }

  public function buildMainMenuItems(
    PhabricatorUser $user,
    PhabricatorController $controller = null) {

    $items = array();

    if ($user->isLoggedIn() && $user->isUserActivated()) {
      $create_id = celerity_generate_unique_node_id();
      Javelin::initBehavior(
        'aphlict-dropdown',
        array(
          'bubbleID' => $create_id,
          'dropdownID' => 'phabricator-quick-create-menu',
          'local' => true,
          'desktop' => true,
          'right' => true,
        ));

      $item = id(new PHUIListItemView())
        ->setName(pht('Create New...'))
        ->setIcon('new-sm')
        ->addClass('core-menu-item')
        ->setHref('/home/create/')
        ->addSigil('quick-create-menu')
        ->setID($create_id)
        ->setOrder(300);
      $items[] = $item;
    }

    return $items;
  }

  public function loadAllQuickCreateItems(PhabricatorUser $viewer) {
    $applications = id(new PhabricatorApplicationQuery())
      ->setViewer($viewer)
      ->withInstalled(true)
      ->execute();

    $items = array();
    foreach ($applications as $application) {
      $app_items = $application->getQuickCreateItems($viewer);
      foreach ($app_items as $app_item) {
        $items[] = $app_item;
      }
    }

    return $items;
  }

  public function buildMainMenuExtraNodes(
    PhabricatorUser $viewer,
    PhabricatorController $controller = null) {

    $items = $this->loadAllQuickCreateItems($viewer);

    $view = new PHUIListView();
    $view->newLabel(pht('Create New...'));
    foreach ($items as $item) {
      $view->addMenuItem($item);
    }

    return phutil_tag(
      'div',
      array(
        'id' => 'phabricator-quick-create-menu',
        'class' => 'phabricator-main-menu-dropdown phui-list-sidenav',
        'style' => 'display: none',
      ),
      $view);
  }

}
