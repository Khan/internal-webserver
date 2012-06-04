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

final class PhabricatorPeopleLogsController
  extends PhabricatorPeopleController {

  public function shouldRequireAdmin() {
    return true;
  }

  public function processRequest() {
    $request = $this->getRequest();
    $user = $request->getUser();

    $filter_activity = $request->getStr('activity');
    $filter_ip = $request->getStr('ip');
    $filter_session = $request->getStr('session');

    $filter_user = $request->getArr('user', array());
    $filter_actor = $request->getArr('actor', array());

    $user_value = array();
    $actor_value = array();

    $phids = array_merge($filter_user, $filter_actor);
    if ($phids) {
      $handles = id(new PhabricatorObjectHandleData($phids))->loadHandles();
      if ($filter_user) {
        $filter_user = reset($filter_user);
        $user_value = array(
          $filter_user => $handles[$filter_user]->getFullName(),
        );
      }

      if ($filter_actor) {
        $filter_actor = reset($filter_actor);
        $actor_value = array(
          $filter_actor => $handles[$filter_actor]->getFullName(),
        );
      }
    }

    $form = new AphrontFormView();
    $form
      ->setUser($user)
      ->appendChild(
        id(new AphrontFormTokenizerControl())
          ->setLabel('Filter Actor')
          ->setName('actor')
          ->setLimit(1)
          ->setValue($actor_value)
          ->setDatasource('/typeahead/common/accounts/'))
      ->appendChild(
        id(new AphrontFormTokenizerControl())
          ->setLabel('Filter User')
          ->setName('user')
          ->setLimit(1)
          ->setValue($user_value)
          ->setDatasource('/typeahead/common/accounts/'))
      ->appendChild(
        id(new AphrontFormSelectControl())
          ->setLabel('Show Activity')
          ->setName('activity')
          ->setValue($filter_activity)
          ->setOptions(
            array(
              ''        => 'All Activity',
              'admin'   => 'Admin Activity',
            )))
      ->appendChild(
        id(new AphrontFormTextControl())
          ->setLabel('Filter IP')
          ->setName('ip')
          ->setValue($filter_ip)
          ->setCaption(
            'Enter an IP (or IP prefix) to show only activity by that remote '.
            'address.'))
      ->appendChild(
        id(new AphrontFormTextControl())
          ->setLabel('Filter Session')
          ->setName('session')
          ->setValue($filter_session))
      ->appendChild(
        id(new AphrontFormSubmitControl())
          ->setValue('Filter Logs'));

    $log_table = new PhabricatorUserLog();
    $conn_r = $log_table->establishConnection('r');

    $where_clause = array();
    $where_clause[] = '1 = 1';

    if ($filter_user) {
      $where_clause[] = qsprintf(
        $conn_r,
        'userPHID = %s',
        $filter_user);
    }

    if ($filter_actor) {
      $where_clause[] = qsprintf(
        $conn_r,
        'actorPHID = %s',
        $filter_actor);
    }

    if ($filter_activity == 'admin') {
      $where_clause[] = qsprintf(
        $conn_r,
        'action NOT IN (%Ls)',
        array(
          PhabricatorUserLog::ACTION_LOGIN,
          PhabricatorUserLog::ACTION_LOGOUT,
          PhabricatorUserLog::ACTION_LOGIN_FAILURE,
        ));
    }

    if ($filter_ip) {
      $where_clause[] = qsprintf(
        $conn_r,
        'remoteAddr LIKE %>',
        $filter_ip);
    }

    if ($filter_session) {
      $where_clause[] = qsprintf(
        $conn_r,
        'session = %s',
        $filter_session);
    }

    $where_clause = '('.implode(') AND (', $where_clause).')';

    $pager = new AphrontPagerView();
    $pager->setURI($request->getRequestURI(), 'page');
    $pager->setOffset($request->getInt('page'));
    $pager->setPageSize(500);

    $logs = $log_table->loadAllWhere(
      '(%Q) ORDER BY dateCreated DESC LIMIT %d, %d',
      $where_clause,
      $pager->getOffset(),
      $pager->getPageSize() + 1);

    $logs = $pager->sliceResults($logs);

    $phids = array();
    foreach ($logs as $log) {
      $phids[$log->getActorPHID()] = true;
      $phids[$log->getUserPHID()] = true;
    }
    $phids = array_keys($phids);
    $handles = id(new PhabricatorObjectHandleData($phids))->loadHandles();

    $rows = array();
    foreach ($logs as $log) {
      $rows[] = array(
        phabricator_date($log->getDateCreated(),$user),
        phabricator_time($log->getDateCreated(),$user),
        $log->getAction(),
        $log->getActorPHID()
          ? phutil_escape_html($handles[$log->getActorPHID()]->getName())
          : null,
        phutil_escape_html($handles[$log->getUserPHID()]->getName()),
        json_encode($log->getOldValue(), true),
        json_encode($log->getNewValue(), true),
        phutil_render_tag(
          'a',
          array(
            'href' => $request
              ->getRequestURI()
              ->alter('ip', $log->getRemoteAddr()),
          ),
          phutil_escape_html($log->getRemoteAddr())),
        phutil_render_tag(
          'a',
          array(
            'href' => $request
              ->getRequestURI()
              ->alter('session', $log->getSession()),
          ),
          phutil_escape_html($log->getSession())),
      );
    }

    $table = new AphrontTableView($rows);
    $table->setHeaders(
      array(
        'Date',
        'Time',
        'Action',
        'Actor',
        'User',
        'Old',
        'New',
        'IP',
        'Session',
      ));
    $table->setColumnClasses(
      array(
        '',
        'right',
        '',
        '',
        '',
        'wrap',
        'wrap',
        '',
        'wide',
      ));

    $panel = new AphrontPanelView();
    $panel->setHeader('Activity Logs');
    $panel->appendChild($table);
    $panel->appendChild($pager);

    $filter = new AphrontListFilterView();
    $filter->appendChild($form);

    return $this->buildStandardPageResponse(
      array(
        $filter,
        $panel,
      ),
      array(
        'title' => 'Activity Logs',
        'tab'   => 'logs',
      ));
  }
}
