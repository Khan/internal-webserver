<?php

final class HeraldTranscriptListController extends HeraldController {

  public function processRequest() {

    $request = $this->getRequest();
    $user = $request->getUser();

    // Get one page of data together with the pager.
    // Pull these objects manually since the serialized fields are gigantic.
    $transcript = new HeraldTranscript();

    $conn_r = $transcript->establishConnection('r');
    $phid = $request->getStr('phid');
    $where_clause = '';
    if ($phid) {
      $where_clause = qsprintf(
        $conn_r,
        'WHERE objectPHID = %s',
        $phid);
    }

    $pager = new AphrontPagerView();
    $pager->setOffset($request->getInt('offset'));
    $pager->setURI($request->getRequestURI(), 'offset');

    $limit_clause = qsprintf(
      $conn_r,
      'LIMIT %d, %d',
      $pager->getOffset(),
      $pager->getPageSize() + 1);

    $data = queryfx_all(
      $conn_r,
      'SELECT id, objectPHID, time, duration, dryRun FROM %T
        %Q
        ORDER BY id DESC
        %Q',
      $transcript->getTableName(),
      $where_clause,
      $limit_clause);

    $data = $pager->sliceResults($data);

    // Render the table.
    $handles = array();
    if ($data) {
      $phids = ipull($data, 'objectPHID', 'objectPHID');
      $handles = $this->loadViewerHandles($phids);
    }

    $rows = array();
    foreach ($data as $xscript) {
      $rows[] = array(
        phabricator_date($xscript['time'], $user),
        phabricator_time($xscript['time'], $user),
        $handles[$xscript['objectPHID']]->renderLink(),
        $xscript['dryRun'] ? 'Yes' : '',
        number_format((int)(1000 * $xscript['duration'])).' ms',
        phutil_tag(
          'a',
          array(
            'href' => '/herald/transcript/'.$xscript['id'].'/',
            'class' => 'button small grey',
          ),
          pht('View Transcript')),
      );
    }

    $table = new AphrontTableView($rows);
    $table->setHeaders(
      array(
        pht('Date'),
        pht('Time'),
        pht('Object'),
        pht('Dry Run'),
        pht('Duration'),
        pht('View'),
      ));
    $table->setColumnClasses(
      array(
        '',
        'right',
        'wide wrap',
        '',
        '',
        'action',
      ));

    // Render the whole page.
    $panel = new AphrontPanelView();
    $panel->setHeader(pht('Herald Transcripts'));
    $panel->appendChild($table);
    $panel->appendChild($pager);
    $panel->setNoBackground();

    $nav = $this->buildSideNavView();
    $nav->selectFilter('transcript');
    $nav->appendChild($panel);

    $crumbs = id($this->buildApplicationCrumbs())
      ->addCrumb(
        id(new PhabricatorCrumbView())
          ->setName(pht('Transcripts')));
    $nav->setCrumbs($crumbs);

    return $this->buildApplicationPage(
      $nav,
      array(
        'title' => pht('Herald Transcripts'),
        'device' => true,
      ));
  }

}
