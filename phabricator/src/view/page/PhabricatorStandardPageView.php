<?php

/**
 * This is a standard Phabricator page with menus, Javelin, DarkConsole, and
 * basic styles.
 *
 */
final class PhabricatorStandardPageView extends PhabricatorBarePageView {

  private $baseURI;
  private $applicationName;
  private $glyph;
  private $menuContent;
  private $showChrome = true;
  private $disableConsole;
  private $searchDefaultScope;
  private $pageObjects = array();
  private $applicationMenu;

  public function setApplicationMenu(PhabricatorMenuView $application_menu) {
    $this->applicationMenu = $application_menu;
    return $this;
  }

  public function getApplicationMenu() {
    return $this->applicationMenu;
  }

  public function setApplicationName($application_name) {
    $this->applicationName = $application_name;
    return $this;
  }

  public function setDisableConsole($disable) {
    $this->disableConsole = $disable;
    return $this;
  }

  public function getApplicationName() {
    return $this->applicationName;
  }

  public function setBaseURI($base_uri) {
    $this->baseURI = $base_uri;
    return $this;
  }

  public function getBaseURI() {
    return $this->baseURI;
  }

  public function setShowChrome($show_chrome) {
    $this->showChrome = $show_chrome;
    return $this;
  }

  public function getShowChrome() {
    return $this->showChrome;
  }

  public function setSearchDefaultScope($search_default_scope) {
    $this->searchDefaultScope = $search_default_scope;
    return $this;
  }

  public function getSearchDefaultScope() {
    return $this->searchDefaultScope;
  }

  public function appendPageObjects(array $objs) {
    foreach ($objs as $obj) {
      $this->pageObjects[] = $obj;
    }
  }

  public function getTitle() {
    $use_glyph = true;
    $request = $this->getRequest();
    if ($request) {
      $user = $request->getUser();
      if ($user && $user->loadPreferences()->getPreference(
            PhabricatorUserPreferences::PREFERENCE_TITLES) !== 'glyph') {
        $use_glyph = false;
      }
    }

    return ($use_glyph ?
            $this->getGlyph() : '['.$this->getApplicationName().']').
      ' '.parent::getTitle();
  }


  protected function willRenderPage() {
    parent::willRenderPage();

    if (!$this->getRequest()) {
      throw new Exception(
        "You must set the Request to render a PhabricatorStandardPageView.");
    }

    $console = $this->getConsole();

    require_celerity_resource('phabricator-core-css');
    require_celerity_resource('phabricator-zindex-css');
    require_celerity_resource('phabricator-core-buttons-css');
    require_celerity_resource('sprite-gradient-css');
    require_celerity_resource('phabricator-standard-page-view');

    Javelin::initBehavior('workflow', array());

    $current_token = null;
    $request = $this->getRequest();
    if ($request) {
      $user = $request->getUser();
      if ($user) {
        $current_token = $user->getCSRFToken();
        $download_form = phabricator_render_form_magic($user);
        $default_img_uri =
          PhabricatorEnv::getCDNURI(
            '/rsrc/image/icon/fatcow/document_black.png'
          );

        Javelin::initBehavior(
          'lightbox-attachments',
          array(
            'defaultImageUri' => $default_img_uri,
            'downloadForm'    => $download_form,
          ));
      }
    }

    Javelin::initBehavior('toggle-class', array());
    Javelin::initBehavior('konami', array());
    Javelin::initBehavior(
      'refresh-csrf',
      array(
        'tokenName' => AphrontRequest::getCSRFTokenName(),
        'header'    => AphrontRequest::getCSRFHeaderName(),
        'current'   => $current_token,
      ));
    Javelin::initBehavior('device');

    if ($console) {
      require_celerity_resource('aphront-dark-console-css');
      Javelin::initBehavior(
        'dark-console',
        array(
          'uri'         => '/~/',
          'request_uri' => $request ? (string) $request->getRequestURI() : '/',
        ));

      // Change this to initBehavior when there is some behavior to initialize
      require_celerity_resource('javelin-behavior-error-log');
    }

    $menu = id(new PhabricatorMainMenuView())
      ->setUser($request->getUser())
      ->setDefaultSearchScope($this->getSearchDefaultScope());

    if ($this->getController()) {
      $menu->setController($this->getController());
    }

    if ($this->getApplicationMenu()) {
      $menu->setApplicationMenu($this->getApplicationMenu());
    }

    $this->menuContent = $menu->render();
  }


  protected function getHead() {
    $monospaced = PhabricatorEnv::getEnvConfig('style.monospace');

    $request = $this->getRequest();
    if ($request) {
      $user = $request->getUser();
      if ($user) {
        $monospaced = nonempty(
          $user->loadPreferences()->getPreference(
            PhabricatorUserPreferences::PREFERENCE_MONOSPACED),
          $monospaced);
      }
    }

    $response = CelerityAPI::getStaticResourceResponse();

    $head = array(
      parent::getHead(),
      '<style type="text/css">'.
        '.PhabricatorMonospaced { font: '.$monospaced.'; }'.
      '</style>',
      $response->renderSingleResource('javelin-magical-init'),
    );

    return implode("\n", $head);
  }

  public function setGlyph($glyph) {
    $this->glyph = $glyph;
    return $this;
  }

  public function getGlyph() {
    return $this->glyph;
  }

  protected function willSendResponse($response) {
    $response = parent::willSendResponse($response);

    $console = $this->getRequest()->getApplicationConfiguration()->getConsole();
    if ($console) {
      $response = str_replace(
        '<darkconsole />',
        $console->render($this->getRequest()),
        $response);
    }

    return $response;
  }

  protected function getBody() {
    $console = $this->getConsole();

    $login_stuff = null;
    $request = $this->getRequest();
    $user = null;
    if ($request) {
      $user = $request->getUser();
      // NOTE: user may not be set here if we caught an exception early
      // in the execution workflow.
      if ($user && $user->getPHID()) {
        $login_stuff =
          phutil_render_tag(
            'a',
            array(
              'href' => '/p/'.$user->getUsername().'/',
            ),
            phutil_escape_html($user->getUsername())).
          ' &middot; '.
          '<a href="/settings/">Settings</a>'.
          ' &middot; '.
          phabricator_render_form(
            $user,
            array(
              'action' => '/search/',
              'method' => 'post',
              'style'  => 'display: inline',
            ),
            '<div class="menu-section menu-section-search">'.
              '<div class="menu-search-container">'.
                '<input type="text" name="query" id="standard-search-box" />'.
                '<button id="standard-search-button">Search</button>'.
              '</div>'.
            '</div>'.
            ' in '.
            AphrontFormSelectControl::renderSelectTag(
              $this->getSearchDefaultScope(),
              PhabricatorSearchScope::getScopeOptions(),
              array(
                'name' => 'scope',
              )).
            ' '.
            '<button>Search</button>');
      }
    }

    $header_chrome = null;
    $footer_chrome = null;
    if ($this->getShowChrome()) {
      $header_chrome = $this->menuContent;

      if (!$this->getDeviceReady()) {
        $footer_chrome = $this->renderFooter();
      }
    }

    $developer_warning = null;
    if (PhabricatorEnv::getEnvConfig('phabricator.show-error-callout') &&
        DarkConsoleErrorLogPluginAPI::getErrors()) {
      $developer_warning =
        '<div class="aphront-developer-error-callout">'.
          pht(
            'This page raised PHP errors. Find them in DarkConsole '.
            'or the error log.').
        '</div>';
    }

    return
      phutil_render_tag(
        'div',
        array(
          'id' => 'base-page',
          'class' => 'phabricator-standard-page',
        ),
        $header_chrome.
        '<div class="phabricator-standard-page-body">'.
          ($console ? '<darkconsole />' : null).
          $developer_warning.
          parent::getBody().
          '<div style="clear: both;"></div>'.
        '</div>').
      $footer_chrome;
  }

  protected function getTail() {
    $request = $this->getRequest();
    $user = $request->getUser();

    $container = null;
    if ($user->isLoggedIn()) {

      $aphlict_object_id = celerity_generate_unique_node_id();
      $aphlict_container_id = celerity_generate_unique_node_id();

      $client_uri = PhabricatorEnv::getEnvConfig('notification.client-uri');
      $client_uri = new PhutilURI($client_uri);
      if ($client_uri->getDomain() == 'localhost') {
        $this_host = $this->getRequest()->getHost();
        $this_host = new PhutilURI('http://'.$this_host.'/');
        $client_uri->setDomain($this_host->getDomain());
      }

      $enable_debug = PhabricatorEnv::getEnvConfig('notification.debug');
      Javelin::initBehavior(
        'aphlict-listen',
        array(
          'id'           => $aphlict_object_id,
          'containerID'  => $aphlict_container_id,
          'server'       => $client_uri->getDomain(),
          'port'         => $client_uri->getPort(),
          'debug'        => $enable_debug,
          'pageObjects'  => array_fill_keys($this->pageObjects, true),
        ));
      $container = phutil_render_tag(
        'div',
        array(
          'id' => $aphlict_container_id,
          'style' => 'position: absolute; width: 0; height: 0;',
        ),
        '');
    }

    $response = CelerityAPI::getStaticResourceResponse();

    $tail = array(
      parent::getTail(),
      $container,
      $response->renderHTMLFooter(),
    );

    return implode("\n", $tail);
  }

  protected function getBodyClasses() {
    $classes = array();

    if (!$this->getShowChrome()) {
      $classes[] = 'phabricator-chromeless-page';
    }

    $agent = idx($_SERVER, 'HTTP_USER_AGENT');

    // Try to guess the device resolution based on UA strings to avoid a flash
    // of incorrectly-styled content.
    $device_guess = 'device-desktop';
    if (preg_match('@iPhone|iPod|(Android.*Chrome/[.0-9]* Mobile)@', $agent)) {
      $device_guess = 'device-phone device';
    } else if (preg_match('@iPad|(Android.*Chrome/)@', $agent)) {
      $device_guess = 'device-tablet device';
    }

    $classes[] = $device_guess;

    return implode(' ', $classes);
  }

  private function getConsole() {
    if ($this->disableConsole) {
      return null;
    }
    return $this->getRequest()->getApplicationConfiguration()->getConsole();
  }

  public function renderFooter() {
    $console = $this->getConsole();

    $foot_links = array();

    $version = PhabricatorEnv::getEnvConfig('phabricator.version');
    $foot_links[] =
      '<a href="http://phabricator.org/">Phabricator</a> '.
      phutil_escape_html($version);

    $foot_links[] =
      '<a href="https://secure.phabricator.com/maniphest/task/create/">'.
        'Report a Bug'.
      '</a>';

    if (PhabricatorEnv::getEnvConfig('darkconsole.enabled') &&
       !PhabricatorEnv::getEnvConfig('darkconsole.always-on')) {
      if ($console) {
        $link = javelin_render_tag(
          'a',
          array(
            'href' => '/~/',
            'sigil' => 'workflow',
          ),
          'Disable DarkConsole');
      } else {
        $link = javelin_render_tag(
          'a',
          array(
            'href' => '/~/',
            'sigil' => 'workflow',
          ),
          'Enable DarkConsole');
      }
      $foot_links[] = $link;
    }

    $foot_links = implode(' &middot; ', $foot_links);

    return
      '<div class="phabricator-page-foot">'.
        $foot_links.
      '</div>';
  }

}
