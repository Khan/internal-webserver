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
 * @group maniphest
 */
abstract class ManiphestController extends PhabricatorController {

  private $defaultQuery;

  public function buildStandardPageResponse($view, array $data) {
    $page = $this->buildStandardPageView();

    $page->setApplicationName('Maniphest');
    $page->setBaseURI('/maniphest/');
    $page->setTitle(idx($data, 'title'));
    $page->setGlyph("\xE2\x9A\x93");
    $page->appendChild($view);
    $page->setSearchDefaultScope(PhabricatorSearchScope::SCOPE_OPEN_TASKS);

    $response = new AphrontWebpageResponse();
    return $response->setContent($page->render());
  }

  protected function buildBaseSideNav() {
    $nav = new AphrontSideNavFilterView();
    $nav->setBaseURI(new PhutilURI('/maniphest/view/'));

    $request = $this->getRequest();
    $user = $request->getUser();

    $custom = id(new ManiphestSavedQuery())->loadAllWhere(
      'userPHID = %s ORDER BY isDefault DESC, name ASC',
      $user->getPHID());

    if ($custom) {
      $nav->addLabel('Saved Queries');
      foreach ($custom as $query) {
        if ($query->getIsDefault()) {
          $this->defaultQuery = $query;
        }
        $nav->addFilter(
          'Q:'.$query->getQueryKey(),
          $query->getName(),
          '/maniphest/view/custom/?key='.$query->getQueryKey());
      }
      $nav->addFilter('saved',  'Edit...', '/maniphest/custom/');
      $nav->addSpacer();
    }

    $nav->addLabel('User Tasks');
    $nav->addFilter('action',       'Assigned');
    $nav->addFilter('created',      'Created');
    $nav->addFilter('subscribed',   'Subscribed');
    $nav->addFilter('triage',       'Need Triage');
    $nav->addSpacer();
    $nav->addLabel('User Projects');
    $nav->addFilter('projecttriage','Need Triage');
    $nav->addFilter('projectall',   'All Tasks');
    $nav->addSpacer();
    $nav->addLabel('All Tasks');
    $nav->addFilter('alltriage',    'Need Triage');
    $nav->addFilter('all',          'All Tasks');
    $nav->addSpacer();
    $nav->addLabel('Custom');
    $nav->addFilter('custom',       'Custom Query');
    $nav->addSpacer();
    $nav->addLabel('Reports');
    $nav->addFilter('report',       'Reports', '/maniphest/report/');

    return $nav;
  }

  protected function getDefaultQuery() {
    return $this->defaultQuery;
  }

}
