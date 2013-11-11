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

  public function setApplicationMenu(PHUIListView $application_menu) {
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

    $title = parent::getTitle();

    $prefix = null;
    if ($use_glyph) {
      $prefix = $this->getGlyph();
    } else {
      $application_name = $this->getApplicationName();
      if (strlen($application_name)) {
        $prefix = '['.$application_name.']';
      }
    }

    if (strlen($prefix)) {
      $title = $prefix.' '.$title;
    }

    return $title;
  }


  protected function willRenderPage() {
    parent::willRenderPage();

    if (!$this->getRequest()) {
      throw new Exception(
        pht(
          "You must set the Request to render a PhabricatorStandardPageView."));
    }

    $console = $this->getConsole();

    require_celerity_resource('phabricator-core-css');
    require_celerity_resource('phabricator-zindex-css');
    require_celerity_resource('phui-button-css');
    require_celerity_resource('phui-spacing-css');
    require_celerity_resource('phui-form-css');
    require_celerity_resource('sprite-gradient-css');
    require_celerity_resource('phabricator-standard-page-view');

    Javelin::initBehavior('workflow', array());

    $request = $this->getRequest();
    $user = null;
    if ($request) {
      $user = $request->getUser();
    }

    if ($user) {
      $default_img_uri =
        PhabricatorEnv::getCDNURI(
          '/rsrc/image/icon/fatcow/document_black.png');
      $download_form = phabricator_form(
        $user,
        array(
          'action' => '#',
          'method' => 'POST',
          'class'  => 'lightbox-download-form',
          'sigil'  => 'download',
        ),
        phutil_tag(
          'button',
          array(),
          pht('Download')));

      Javelin::initBehavior(
        'lightbox-attachments',
        array(
          'defaultImageUri' => $default_img_uri,
          'downloadForm'    => $download_form,
        ));
    }

    Javelin::initBehavior('aphront-form-disable-on-submit');
    Javelin::initBehavior('toggle-class', array());
    Javelin::initBehavior('konami', array());
    Javelin::initBehavior('history-install');
    Javelin::initBehavior('phabricator-gesture');

    $current_token = null;
    if ($user) {
      $current_token = $user->getCSRFToken();
    }

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

      $headers = array();
      if (DarkConsoleXHProfPluginAPI::isProfilerStarted()) {
        $headers[DarkConsoleXHProfPluginAPI::getProfilerHeader()] = 'page';
      }

      Javelin::initBehavior(
        'dark-console',
        array(
          // NOTE: We use a generic label here to prevent input reflection
          // and mitigate compression attacks like BREACH. See discussion in
          // T3684.
          'uri' => pht('Main Request'),
          'selected' => $user ? $user->getConsoleTab() : null,
          'visible'  => $user ? (int)$user->getConsoleVisible() : true,
          'headers' => $headers,
        ));

      // Change this to initBehavior when there is some behavior to initialize
      require_celerity_resource('javelin-behavior-error-log');
    }

    if ($user) {
      $viewer = $user;
    } else {
      $viewer = new PhabricatorUser();
    }

    $menu = id(new PhabricatorMainMenuView())
      ->setUser($viewer)
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
    $monospaced_win = PhabricatorEnv::getEnvConfig('style.monospace.windows');

    $request = $this->getRequest();
    if ($request) {
      $user = $request->getUser();
      if ($user) {
        $pref = $user->loadPreferences()->getPreference(
            PhabricatorUserPreferences::PREFERENCE_MONOSPACED);
        $monospaced = nonempty($pref, $monospaced);
        $monospaced_win = nonempty($pref, $monospaced_win);
      }
    }

    $response = CelerityAPI::getStaticResourceResponse();

    return hsprintf(
      '%s<style type="text/css">'.
      '.PhabricatorMonospaced, '.
      '.phabricator-remarkup .remarkup-code-block { font: %s; } '.
      '.platform-windows .PhabricatorMonospaced, '.
      '.platform-windows .phabricator-remarkup '.
        '.remarkup-code-block { font: %s; }'.
      '</style>%s',
      parent::getHead(),
      phutil_safe_html($monospaced),
      phutil_safe_html($monospaced_win),
      $response->renderSingleResource('javelin-magical-init'));
  }

  public function setGlyph($glyph) {
    $this->glyph = $glyph;
    return $this;
  }

  public function getGlyph() {
    return $this->glyph;
  }

  protected function willSendResponse($response) {
    $request = $this->getRequest();
    $response = parent::willSendResponse($response);

    $console = $request->getApplicationConfiguration()->getConsole();

    if ($console) {
      $response = PhutilSafeHTML::applyFunction(
        'str_replace',
        hsprintf('<darkconsole />'),
        $console->render($request),
        $response);
    }

    return $response;
  }

  protected function getBody() {
    $console = $this->getConsole();

    $user = null;
    $request = $this->getRequest();
    if ($request) {
      $user = $request->getUser();
    }

    $header_chrome = null;
    if ($this->getShowChrome()) {
      $header_chrome = $this->menuContent;
    }

    $developer_warning = null;
    if (PhabricatorEnv::getEnvConfig('phabricator.developer-mode') &&
        DarkConsoleErrorLogPluginAPI::getErrors()) {
      $developer_warning = phutil_tag_div(
        'aphront-developer-error-callout',
        pht(
          'This page raised PHP errors. Find them in DarkConsole '.
          'or the error log.'));
    }

    // Render the "you have unresolved setup issues..." warning.
    $setup_warning = null;
    if ($user && $user->getIsAdmin()) {
      $open = PhabricatorSetupCheck::getOpenSetupIssueCount();
      if ($open) {
        $setup_warning = phutil_tag_div(
          'setup-warning-callout',
          phutil_tag(
            'a',
            array(
              'href' => '/config/issue/',
            ),
            pht('You have %d unresolved setup issue(s)...', $open)));
      }
    }

    return
      phutil_tag(
        'div',
        array(
          'id' => 'base-page',
          'class' => 'phabricator-standard-page',
        ),
        array(
          $developer_warning,
          $setup_warning,
          $header_chrome,
          phutil_tag_div('phabricator-standard-page-body', array(
            ($console ? hsprintf('<darkconsole />') : null),
            parent::getBody(),
            phutil_tag('div', array('style' => 'clear: both;')),
          )),
        ));
  }

  protected function getTail() {
    $request = $this->getRequest();
    $user = $request->getUser();

    $container = null;
    if ($user && $user->isLoggedIn()) {

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
      $container = phutil_tag(
        'div',
        array(
          'id' => $aphlict_container_id,
          'style' =>
            'position: absolute; width: 0; height: 0; overflow: hidden;',
        ),
        '');
    }

    $response = CelerityAPI::getStaticResourceResponse();

    $tail = array(
      parent::getTail(),
      $container,
      $response->renderHTMLFooter(),
    );

    return phutil_implode_html("\n", $tail);
  }

  protected function getBodyClasses() {
    $classes = array();

    if (!$this->getShowChrome()) {
      $classes[] = 'phabricator-chromeless-page';
    }

    $agent = AphrontRequest::getHTTPHeader('User-Agent');

    // Try to guess the device resolution based on UA strings to avoid a flash
    // of incorrectly-styled content.
    $device_guess = 'device-desktop';
    if (preg_match('@iPhone|iPod|(Android.*Chrome/[.0-9]* Mobile)@', $agent)) {
      $device_guess = 'device-phone device';
    } else if (preg_match('@iPad|(Android.*Chrome/)@', $agent)) {
      $device_guess = 'device-tablet device';
    }

    $classes[] = $device_guess;

    if (preg_match('@Windows@', $agent)) {
      $classes[] = 'platform-windows';
    } else if (preg_match('@Macintosh@', $agent)) {
      $classes[] = 'platform-mac';
    } else if (preg_match('@X11@', $agent)) {
      $classes[] = 'platform-linux';
    }

    if ($this->getRequest()->getStr('__print__')) {
      $classes[] = 'printable';
    }

    return implode(' ', $classes);
  }

  private function getConsole() {
    if ($this->disableConsole) {
      return null;
    }
    return $this->getRequest()->getApplicationConfiguration()->getConsole();
  }

}
