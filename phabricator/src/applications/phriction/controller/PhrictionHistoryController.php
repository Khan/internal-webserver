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

/**
 * @group phriction
 */
final class PhrictionHistoryController
  extends PhrictionController {

  private $slug;

  public function willProcessRequest(array $data) {
    $this->slug = $data['slug'];
  }

  public function processRequest() {

    $request = $this->getRequest();
    $user = $request->getUser();

    $document = id(new PhrictionDocument())->loadOneWhere(
      'slug = %s',
      PhabricatorSlug::normalize($this->slug));

    if (!$document) {
      return new Aphront404Response();
    }

    $current = id(new PhrictionContent())->load($document->getContentID());

    $pager = new AphrontPagerView();
    $pager->setOffset($request->getInt('page'));
    $pager->setURI($request->getRequestURI(), 'page');

    $history = id(new PhrictionContent())->loadAllWhere(
      'documentID = %d ORDER BY version DESC LIMIT %d, %d',
      $document->getID(),
      $pager->getOffset(),
      $pager->getPageSize() + 1);
    $history = $pager->sliceResults($history);

    $author_phids = mpull($history, 'getAuthorPHID');
    $handles = id(new PhabricatorObjectHandleData($author_phids))
      ->loadHandles();

    $rows = array();
    foreach ($history as $content) {

      $uri = PhrictionDocument::getSlugURI($document->getSlug());
      $version = $content->getVersion();

      $diff_uri = new PhutilURI('/phriction/diff/'.$document->getID().'/');

      $vs_previous = '<em>Created</em>';
      if ($content->getVersion() != 1) {
        $uri = $diff_uri
          ->alter('l', $content->getVersion() - 1)
          ->alter('r', $content->getVersion());
        $vs_previous = phutil_render_tag(
          'a',
          array(
            'href' => $uri,
          ),
          'Show Change');
      }

      $vs_head = '<em>Current</em>';
      if ($content->getID() != $document->getContentID()) {
        $uri = $diff_uri
          ->alter('l', $content->getVersion())
          ->alter('r', $current->getVersion());

        $vs_head = phutil_render_tag(
          'a',
          array(
            'href' => $uri,
          ),
          'Show Later Changes');
      }

      $change_type = PhrictionChangeType::getChangeTypeLabel(
        $content->getChangeType());

      $rows[] = array(
        phabricator_date($content->getDateCreated(), $user),
        phabricator_time($content->getDateCreated(), $user),
        phutil_render_tag(
          'a',
          array(
            'href' => $uri.'?v='.$version,
          ),
          'Version '.$version),
        $handles[$content->getAuthorPHID()]->renderLink(),
        $change_type,
        phutil_escape_html($content->getDescription()),
        $vs_previous,
        $vs_head,
      );
    }

    $crumbs = new AphrontCrumbsView();
    $crumbs->setCrumbs(
      array(
        'Phriction',
        phutil_render_tag(
          'a',
          array(
            'href' => PhrictionDocument::getSlugURI($document->getSlug()),
          ),
          phutil_escape_html($current->getTitle())
        ),
        'History',
      ));


    $table = new AphrontTableView($rows);
    $table->setHeaders(
      array(
        'Date',
        'Time',
        'Version',
        'Author',
        'Type',
        'Description',
        'Against Previous',
        'Against Current',
      ));
    $table->setColumnClasses(
      array(
        '',
        'right',
        'pri',
        '',
        '',
        'wide',
        '',
        '',
      ));

    $panel = new AphrontPanelView();
    $panel->setHeader('Document History');
    $panel->appendChild($table);
    $panel->appendChild($pager);

    return $this->buildStandardPageResponse(
      array(
        $crumbs,
        $panel,
      ),
      array(
        'title'     => 'Document History',
      ));

  }

}
