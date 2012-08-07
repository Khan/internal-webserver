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

final class DifferentialRevisionStatsController extends DifferentialController {
  private $filter;

  public function shouldRequireLogin() {
    return true;
  }

  private function loadRevisions($phid) {
    $table = new DifferentialRevision();
    $conn_r = $table->establishConnection('r');
    $rows = queryfx_all(
      $conn_r,
      'SELECT revisions.* FROM %T revisions ' .
      'JOIN %T comments ON comments.revisionID = revisions.id ' .
      'JOIN (' .
      ' SELECT revisionID FROM %T WHERE objectPHID = %s ' .
      ' UNION ALL ' .
      ' SELECT id from differential_revision WHERE authorPHID = %s) rel ' .
      'ON (comments.revisionID = rel.revisionID)' .
      'WHERE comments.action = %s' .
      'AND comments.authorPHID = %s',
      $table->getTableName(),
      id(new DifferentialComment())->getTableName(),
      DifferentialRevision::RELATIONSHIP_TABLE,
      $phid,
      $phid,
      $this->filter,
      $phid
    );
    return $table->loadAllFromArray($rows);
  }

  private function loadComments($phid) {
    $table = new DifferentialComment();
    $conn_r = $table->establishConnection('r');
    $rows = queryfx_all(
      $conn_r,
      'SELECT comments.* FROM %T comments ' .
      'JOIN (' .
      ' SELECT revisionID FROM %T WHERE objectPHID = %s ' .
      ' UNION ALL ' .
      ' SELECT id from differential_revision WHERE authorPHID = %s) rel ' .
      'ON (comments.revisionID = rel.revisionID)' .
      'WHERE comments.action = %s' .
      'AND comments.authorPHID = %s',
      $table->getTableName(),
      DifferentialRevision::RELATIONSHIP_TABLE,
      $phid,
      $phid,
      $this->filter,
      $phid
    );

    return $table->loadAllFromArray($rows);
  }
  public function willProcessRequest(array $data) {
    $this->filter = idx($data, 'filter');
  }

  public function processRequest() {
    $request = $this->getRequest();
    $user = $request->getUser();

    if ($request->isFormPost()) {
      $phid_arr = $request->getArr('view_user');
      $view_target = head($phid_arr);
      return id(new AphrontRedirectResponse())
        ->setURI($request->getRequestURI()->alter('phid', $view_target));
    }

    $params = array_filter(
      array(
        'phid' => $request->getStr('phid'),
      ));

    // Fill in the defaults we'll actually use for calculations if any
    // parameters are missing.
    $params += array(
      'phid' => $user->getPHID(),
    );

    $side_nav = new AphrontSideNavFilterView();
    $side_nav->setBaseURI(id(new PhutilURI('/differential/stats/'))
                          ->alter('phid', $params['phid']));
    foreach (array(
               DifferentialAction::ACTION_CLOSE,
               DifferentialAction::ACTION_ACCEPT,
               DifferentialAction::ACTION_REJECT,
               DifferentialAction::ACTION_UPDATE,
               DifferentialAction::ACTION_COMMENT,
             ) as $action) {
      $verb = ucfirst(DifferentialAction::getActionPastTenseVerb($action));
      $side_nav->addFilter($action, $verb);
    }
    $this->filter =
      $side_nav->selectFilter($this->filter,
                              DifferentialAction::ACTION_CLOSE);

    $panels = array();
    $handles = id(new PhabricatorObjectHandleData(array($params['phid'])))
                  ->loadHandles();

    $filter_form = id(new AphrontFormView())
      ->setAction('/differential/stats/'.$this->filter.'/')
      ->setUser($user);

    $filter_form->appendChild(
      $this->renderControl($params['phid'], $handles));
    $filter_form->appendChild(id(new AphrontFormSubmitControl())
                              ->setValue('Filter Revisions'));

    $side_nav->appendChild($filter_form);

    $comments = $this->loadComments($params['phid']);
    $revisions = $this->loadRevisions($params['phid']);

    $panel = new AphrontPanelView();
    $panel->setHeader('Differential rate analysis');
    $panel->appendChild(
      id(new DifferentialRevisionStatsView())
      ->setComments($comments)
      ->setRevisions($revisions)
      ->setUser($user));
    $panels[] = $panel;

    foreach ($panels as $panel) {
      $side_nav->appendChild($panel);
    }

    return $this->buildStandardPageResponse(
      $side_nav,
      array(
        'title' => 'Differential statistics',
      ));
  }

  private function renderControl($view_phid, $handles) {
    $value = array();
    if ($view_phid) {
      $value = array(
        $view_phid => $handles[$view_phid]->getFullName(),
      );
    }
    return id(new AphrontFormTokenizerControl())
      ->setDatasource('/typeahead/common/users/')
      ->setLabel('View User')
      ->setName('view_user')
      ->setValue($value)
      ->setLimit(1);
  }

}
