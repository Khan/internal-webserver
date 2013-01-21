<?php

final class PhabricatorConfigAllController
  extends PhabricatorConfigController {

  public function processRequest() {
    $request = $this->getRequest();
    $user = $request->getUser();

    $rows = array();
    $options = PhabricatorApplicationConfigOptions::loadAllOptions();
    ksort($options);
    foreach ($options as $option) {
      $key = $option->getKey();

      if ($option->getMasked()) {
        $value = '<em>'.pht('Masked').'</em>';
      } else if ($option->getHidden()) {
        $value = '<em>'.pht('Hidden').'</em>';
      } else {
        $value = PhabricatorEnv::getEnvConfig($key);
        $value = PhabricatorConfigJSON::prettyPrintJSON($value);
        $value = phutil_escape_html($value);
      }

      $rows[] = array(
        phutil_render_tag(
          'a',
          array(
            'href' => $this->getApplicationURI('edit/'.$key.'/'),
          ),
          phutil_escape_html($key)),
        $value,
      );
    }
    $table = id(new AphrontTableView($rows))
      ->setDeviceReadyTable(true)
      ->setColumnClasses(
        array(
          '',
          'wide',
        ))
      ->setHeaders(
        array(
          pht('Key'),
          pht('Value'),
        ));

    $title = pht('Current Settings');

    $crumbs = $this
      ->buildApplicationCrumbs()
      ->addCrumb(
        id(new PhabricatorCrumbView())
          ->setName($title));

    $panel = new AphrontPanelView();
    $panel->appendChild($table);
    $panel->setNoBackground();

    $phabricator_root = dirname(phutil_get_library_root('phabricator'));
    $future = id(new ExecFuture('git log --format=%%H -n 1 --'))
      ->setCWD($phabricator_root);
    list($err, $stdout) = $future->resolve();
    if (!$err) {
      $display_version = trim($stdout);
    } else {
      $display_version = pht('Unknown');
    }
    $version_property_list = id(new PhabricatorPropertyListView());
    $version_property_list->addProperty('Version',
      phutil_escape_html($display_version));
    $version_path = $phabricator_root.'/conf/local/VERSION';
    if (Filesystem::pathExists($version_path)) {
      $version_from_file = Filesystem::readFile($version_path);
      $version_property_list->addProperty('Local Version',
        phutil_escape_html($version_from_file));
    }

    $nav = $this->buildSideNavView();
    $nav->selectFilter('all/');
    $nav->setCrumbs($crumbs);
    $nav->appendChild($version_property_list);
    $nav->appendChild($panel);



    return $this->buildApplicationPage(
      $nav,
      array(
        'title' => $title,
        'device' => true,
      )
    );
  }

}
