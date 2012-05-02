<?php

/*
 * Copyright 2012 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

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
      $handles = id(new PhabricatorObjectHandleData($phids))
        ->loadHandles();
    }

    $rows = array();
    foreach ($data as $xscript) {
      $rows[] = array(
        phabricator_date($xscript['time'], $user),
        phabricator_time($xscript['time'], $user),
        $handles[$xscript['objectPHID']]->renderLink(),
        $xscript['dryRun'] ? 'Yes' : '',
        number_format((int)(1000 * $xscript['duration'])).' ms',
        phutil_render_tag(
          'a',
          array(
            'href' => '/herald/transcript/'.$xscript['id'].'/',
            'class' => 'button small grey',
          ),
          'View Transcript'),
      );
    }

    $table = new AphrontTableView($rows);
    $table->setHeaders(
      array(
        'Date',
        'Time',
        'Object',
        'Dry Run',
        'Duration',
        'View',
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
    $panel->setHeader('Herald Transcripts');
    $panel->appendChild($table);
    $panel->appendChild($pager);

    $nav = $this->renderNav();
    $nav->selectFilter('transcript');
    $nav->appendChild($panel);

    return $this->buildStandardPageResponse(
      $nav,
      array(
        'title' => 'Herald Transcripts',
        'tab' => 'transcripts',
      ));
  }

}
