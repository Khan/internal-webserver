<?php

final class PhabricatorConfigResponse extends AphrontHTMLResponse {

  private $view;

  public function setView(PhabricatorSetupIssueView $view) {
    $this->view = $view;
    return $this;
  }

  public function buildResponseString() {
    // Check to make sure we aren't requesting this via ajax or conduit
    if (isset($_REQUEST['__ajax__']) || isset($_REQUEST['__conduit__'])) {
      // We don't want to flood the console with html, just return a simple
      // message for now.
      return pht(
        "This install has a fatal setup error, access the internet web ".
        "version to view details and resolve it.");
    }

    $resources = $this->buildResources();

    $view = $this->view->render();

    $template = <<<EOTEMPLATE
<!doctype html>
<html>
  <head>
    <title>Phabricator Setup</title>
    {$resources}
  </head>
  <body class="setup-fatal">
    {$view}
  </body>
</html>
EOTEMPLATE;

    return $template;
  }

  private function buildResources() {
    $css = array(
      'application/config/config-template.css',
      'application/config/setup-issue.css',
    );

    $webroot = dirname(phutil_get_library_root('phabricator')).'/webroot/';

    $resources = array();
    foreach ($css as $path) {
      $resources[] = '<style type="text/css">';
      $resources[] = Filesystem::readFile($webroot.'/rsrc/css/'.$path);
      $resources[] = '</style>';
    }
    return implode("\n", $resources);
  }


}
