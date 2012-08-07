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

final class PhabricatorProjectListController
  extends PhabricatorProjectController {

  private $filter;

  public function willProcessRequest(array $data) {
    $this->filter = idx($data, 'filter');
  }

  public function processRequest() {
    $request = $this->getRequest();

    $nav = new AphrontSideNavFilterView();
    $nav
      ->setBaseURI(new PhutilURI('/project/filter/'))
      ->addLabel('User')
      ->addFilter('active',   'Active')
      ->addFilter('owned',    'Owned')
      ->addSpacer()
      ->addLabel('All')
      ->addFilter('all',      'All Projects')
      ->addFilter('allactive','Active Projects');
    $this->filter = $nav->selectFilter($this->filter, 'active');

    $pager = new AphrontPagerView();
    $pager->setPageSize(250);
    $pager->setURI($request->getRequestURI(), 'page');
    $pager->setOffset($request->getInt('page'));

    $query = new PhabricatorProjectQuery();
    $query->setOffset($pager->getOffset());
    $query->setLimit($pager->getPageSize() + 1);

    $view_phid = $request->getUser()->getPHID();

    $status_filter = PhabricatorProjectQuery::STATUS_ANY;

    switch ($this->filter) {
      case 'active':
        $table_header = 'Your Projects';
        $query->setMembers(array($view_phid));
        $query->withStatus(PhabricatorProjectQuery::STATUS_ACTIVE);
        break;
      case 'owned':
        $table_header = 'Owned Projects';
        $query->setOwners(array($view_phid));
        $query->withStatus($status_filter);
        break;
      case 'allactive':
        $status_filter = PhabricatorProjectQuery::STATUS_ACTIVE;
        $table_header = 'Active Projects';
        // fallthrough
      case 'all':
        $table_header = 'All Projects';
        $query->withStatus($status_filter);
        break;
    }

    $projects = $query->execute();
    $projects = $pager->sliceResults($projects);

    $project_phids = mpull($projects, 'getPHID');

    $profiles = array();
    if ($projects) {
      $profiles = id(new PhabricatorProjectProfile())->loadAllWhere(
        'projectPHID in (%Ls)',
        $project_phids);
      $profiles = mpull($profiles, null, 'getProjectPHID');
    }

    $affil_groups = array();
    if ($projects) {
      $affil_groups = PhabricatorProjectAffiliation::loadAllForProjectPHIDs(
        $project_phids);
    }

    $tasks = array();
    $groups = array();
    if ($project_phids) {
      $query = id(new ManiphestTaskQuery())
        ->withProjects($project_phids)
        ->withAnyProject(true)
        ->withStatus(ManiphestTaskQuery::STATUS_OPEN)
        ->setLimit(PHP_INT_MAX);

      $tasks = $query->execute();
      foreach ($tasks as $task) {
        foreach ($task->getProjectPHIDs() as $phid) {
          $groups[$phid][] = $task;
        }
      }
    }


    $rows = array();
    foreach ($projects as $project) {
      $phid = $project->getPHID();

      $profile = idx($profiles, $phid);
      $affiliations = $affil_groups[$phid];

      $group = idx($groups, $phid, array());
      $task_count = count($group);

      $population = count($affiliations);

      if ($profile) {
        $blurb = $profile->getBlurb();
        $blurb = phutil_utf8_shorten($blurb, 64);
      } else {
        $blurb = null;
      }


      $rows[] = array(
        phutil_render_tag(
          'a',
          array(
            'href' => '/project/view/'.$project->getID().'/',
          ),
          phutil_escape_html($project->getName())),
        phutil_escape_html(
          PhabricatorProjectStatus::getNameForStatus($project->getStatus())),
        phutil_escape_html($blurb),
        phutil_escape_html($population),
        phutil_render_tag(
          'a',
          array(
            'href' => '/maniphest/view/all/?projects='.$phid,
          ),
          phutil_escape_html($task_count)),
      );
    }

    $table = new AphrontTableView($rows);
    $table->setHeaders(
      array(
        'Project',
        'Status',
        'Description',
        'Population',
        'Open Tasks',
      ));
    $table->setColumnClasses(
      array(
        'pri',
        '',
        'wide',
        '',
        ''
      ));

    $panel = new AphrontPanelView();
    $panel->setHeader($table_header);
    $panel->setCreateButton('Create New Project', '/project/create/');
    $panel->appendChild($table);
    $panel->appendChild($pager);

    $nav->appendChild($panel);

    return $this->buildStandardPageResponse(
      $nav,
      array(
        'title' => 'Projects',
      ));
  }
}
