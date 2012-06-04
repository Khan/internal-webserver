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
final class ManiphestTaskListController extends ManiphestController {

  const DEFAULT_PAGE_SIZE = 1000;

  private $view;

  public function willProcessRequest(array $data) {
    $this->view = idx($data, 'view');
  }

  private function getArrToStrList($key) {
    $arr = $this->getRequest()->getArr($key);
    $arr = implode(',', $arr);
    return nonempty($arr, null);
  }

  public function processRequest() {

    $request = $this->getRequest();
    $user = $request->getUser();

    if ($request->isFormPost()) {
      // Redirect to GET so URIs can be copy/pasted.

      $task_ids   = $request->getStr('set_tasks');
      $task_ids   = nonempty($task_ids, null);

      $uri = $request->getRequestURI()
        ->alter('users',      $this->getArrToStrList('set_users'))
        ->alter('projects',   $this->getArrToStrList('set_projects'))
        ->alter('xprojects',  $this->getArrToStrList('set_xprojects'))
        ->alter('owners',     $this->getArrToStrList('set_owners'))
        ->alter('authors',    $this->getArrToStrList('set_authors'))
        ->alter('tasks', $task_ids);

      return id(new AphrontRedirectResponse())->setURI($uri);
    }

    $nav = $this->buildBaseSideNav();

    $has_filter = array(
      'action' => true,
      'created' => true,
      'subscribed' => true,
      'triage' => true,
      'projecttriage' => true,
      'projectall' => true,
    );

    $query = null;
    $key = $request->getStr('key');
    if (!$key && !$this->view) {
      if ($this->getDefaultQuery()) {
        $key = $this->getDefaultQuery()->getQueryKey();
      }
    }

    if ($key) {
      $query = id(new PhabricatorSearchQuery())->loadOneWhere(
        'queryKey = %s',
        $key);
    }

    // If the user is running a saved query, load query parameters from that
    // query. Otherwise, build a new query object from the HTTP request.

    if ($query) {
      $nav->selectFilter('Q:'.$query->getQueryKey(), 'custom');
      $this->view = 'custom';
    } else {
      $this->view = $nav->selectFilter($this->view, 'action');
      $query = $this->buildQueryFromRequest();
    }

    // Execute the query.

    list($tasks, $handles, $total_count) = self::loadTasks($query);

    // Extract information we need to render the filters from the query.

    $user_phids     = $query->getParameter('userPHIDs', array());
    $task_ids       = $query->getParameter('taskIDs', array());
    $owner_phids    = $query->getParameter('ownerPHIDs', array());
    $author_phids   = $query->getParameter('authorPHIDs', array());
    $project_phids  = $query->getParameter('projectPHIDs', array());
    $exclude_project_phids = $query->getParameter(
      'excludeProjectPHIDs',
      array());
    $page_size = $query->getParameter('limit');
    $page = $query->getParameter('offset');

    $q_status = $query->getParameter('status');
    $q_group  = $query->getParameter('group');
    $q_order  = $query->getParameter('order');

    $form = id(new AphrontFormView())
      ->setUser($user)
      ->setAction(
          $request->getRequestURI()
            ->alter('key', null)
            ->alter(
              $this->getStatusRequestKey(),
              $this->getStatusRequestValue($q_status))
            ->alter(
              $this->getOrderRequestKey(),
              $this->getOrderRequestValue($q_order))
            ->alter(
              $this->getGroupRequestKey(),
              $this->getGroupRequestValue($q_group)));

    if (isset($has_filter[$this->view])) {
      $tokens = array();
      foreach ($user_phids as $phid) {
        $tokens[$phid] = $handles[$phid]->getFullName();
      }
      $form->appendChild(
        id(new AphrontFormTokenizerControl())
          ->setDatasource('/typeahead/common/searchowner/')
          ->setName('set_users')
          ->setLabel('Users')
          ->setValue($tokens));
    }

    if ($this->view == 'custom') {
      $form->appendChild(
        id(new AphrontFormTextControl())
          ->setName('set_tasks')
          ->setLabel('Task IDs')
          ->setValue(join(',', $task_ids))
      );

      $tokens = array();
      foreach ($owner_phids as $phid) {
        $tokens[$phid] = $handles[$phid]->getFullName();
      }
      $form->appendChild(
        id(new AphrontFormTokenizerControl())
          ->setDatasource('/typeahead/common/searchowner/')
          ->setName('set_owners')
          ->setLabel('Owners')
          ->setValue($tokens));

      $tokens = array();
      foreach ($author_phids as $phid) {
        $tokens[$phid] = $handles[$phid]->getFullName();
      }
      $form->appendChild(
        id(new AphrontFormTokenizerControl())
          ->setDatasource('/typeahead/common/users/')
          ->setName('set_authors')
          ->setLabel('Authors')
          ->setValue($tokens));
    }

    $tokens = array();
    foreach ($project_phids as $phid) {
      $tokens[$phid] = $handles[$phid]->getFullName();
    }
    if ($this->view != 'projectall' && $this->view != 'projecttriage') {
      $form->appendChild(
        id(new AphrontFormTokenizerControl())
          ->setDatasource('/typeahead/common/searchproject/')
          ->setName('set_projects')
          ->setLabel('Projects')
          ->setValue($tokens));
    }

    if ($this->view == 'custom') {
      $tokens = array();
      foreach ($exclude_project_phids as $phid) {
        $tokens[$phid] = $handles[$phid]->getFullName();
      }
      $form->appendChild(
        id(new AphrontFormTokenizerControl())
          ->setDatasource('/typeahead/common/projects/')
          ->setName('set_xprojects')
          ->setLabel('Exclude Projects')
          ->setValue($tokens));
    }

    $form
      ->appendChild($this->renderStatusControl($q_status))
      ->appendChild($this->renderGroupControl($q_group))
      ->appendChild($this->renderOrderControl($q_order));

    $submit = id(new AphrontFormSubmitControl())
      ->setValue('Filter Tasks');

    // Only show "Save..." for novel queries which have some kind of query
    // parameters set.
    if ($this->view === 'custom'
        && empty($key)
        && $request->getRequestURI()->getQueryParams()) {
      $submit->addCancelButton(
        '/maniphest/custom/edit/?key='.$query->getQueryKey(),
        'Save Custom Query...');
    }

    $form->appendChild($submit);

    $create_uri = new PhutilURI('/maniphest/task/create/');
    if ($project_phids) {
      // If we have project filters selected, use them as defaults for task
      // creation.
      $create_uri->setQueryParam('projects', implode(';', $project_phids));
    }

    $filter = new AphrontListFilterView();
    $filter->addButton(
      phutil_render_tag(
        'a',
        array(
          'href'  => (string)$create_uri,
          'class' => 'green button',
        ),
        'Create New Task'));

    if (empty($key)) {
      $filter->appendChild($form);
    }

    $nav->appendChild($filter);

    $have_tasks = false;
    foreach ($tasks as $group => $list) {
      if (count($list)) {
        $have_tasks = true;
        break;
      }
    }

    require_celerity_resource('maniphest-task-summary-css');

    $list_container = new AphrontNullView();
    $list_container->appendChild('<div class="maniphest-list-container">');

    if (!$have_tasks) {
      $list_container->appendChild(
        '<h1 class="maniphest-task-group-header">'.
          'No matching tasks.'.
        '</h1>');
    } else {
      $pager = new AphrontPagerView();
      $pager->setURI($request->getRequestURI(), 'offset');
      $pager->setPageSize($page_size);
      $pager->setOffset($page);
      $pager->setCount($total_count);

      $cur = ($pager->getOffset() + 1);
      $max = min($pager->getOffset() + $page_size, $total_count);
      $tot = $total_count;

      $cur = number_format($cur);
      $max = number_format($max);
      $tot = number_format($tot);

      $list_container->appendChild(
        '<div class="maniphest-total-result-count">'.
          "Displaying tasks {$cur} - {$max} of {$tot}.".
        '</div>');

      $selector = new AphrontNullView();

      $group = $query->getParameter('group');
      $order = $query->getParameter('order');
      $is_draggable =
        ($group == 'priority') ||
        ($group == 'none' && $order == 'priority');

      $lists = new AphrontNullView();
      $lists->appendChild('<div class="maniphest-group-container">');
      foreach ($tasks as $group => $list) {
        $task_list = new ManiphestTaskListView();
        $task_list->setShowBatchControls(true);
        if ($is_draggable) {
          $task_list->setShowSubpriorityControls(true);
        }
        $task_list->setUser($user);
        $task_list->setTasks($list);
        $task_list->setHandles($handles);

        $count = number_format(count($list));

        $lists->appendChild(
          javelin_render_tag(
            'h1',
            array(
              'class' => 'maniphest-task-group-header',
              'sigil' => 'task-group',
              'meta'  => array(
                'priority' => head($list)->getPriority(),
              ),
            ),
            phutil_escape_html($group).' ('.$count.')'));

        $lists->appendChild($task_list);
      }
      $lists->appendChild('</div>');
      $selector->appendChild($lists);


      $selector->appendChild($this->renderBatchEditor($query));

      $form_id = celerity_generate_unique_node_id();
      $selector = phabricator_render_form(
        $user,
        array(
          'method' => 'POST',
          'action' => '/maniphest/batch/',
          'id'     => $form_id,
        ),
        $selector->render());

      $list_container->appendChild($selector);
      $list_container->appendChild($pager);

      Javelin::initBehavior(
        'maniphest-subpriority-editor',
        array(
          'root'  => $form_id,
          'uri'   =>  '/maniphest/subpriority/',
        ));
    }

    $list_container->appendChild('</div>');
    $nav->appendChild($list_container);

    return $this->buildStandardPageResponse(
      $nav,
      array(
        'title' => 'Task List',
      ));
  }

  public static function loadTasks(PhabricatorSearchQuery $search_query) {
    $any_project = false;
    $user_phids = $search_query->getParameter('userPHIDs', array());
    $project_phids = $search_query->getParameter('projectPHIDs', array());
    $task_ids = $search_query->getParameter('taskIDs', array());
    $xproject_phids = $search_query->getParameter(
      'excludeProjectPHIDs',
      array());
    $owner_phids = $search_query->getParameter('ownerPHIDs', array());
    $author_phids = $search_query->getParameter('authorPHIDs', array());

    $query = new ManiphestTaskQuery();
    $query->withProjects($project_phids);
    $query->withTaskIDs($task_ids);

    if ($xproject_phids) {
      $query->withoutProjects($xproject_phids);
    }

    if ($owner_phids) {
      $query->withOwners($owner_phids);
    }

    if ($author_phids) {
      $query->withAuthors($author_phids);
    }

    $status = $search_query->getParameter('status', 'all');
    if (!empty($status['open']) && !empty($status['closed'])) {
      $query->withStatus(ManiphestTaskQuery::STATUS_ANY);
    } else if (!empty($status['open'])) {
      $query->withStatus(ManiphestTaskQuery::STATUS_OPEN);
    } else {
      $query->withStatus(ManiphestTaskQuery::STATUS_CLOSED);
    }

    switch ($search_query->getParameter('view')) {
      case 'action':
        $query->withOwners($user_phids);
        break;
      case 'created':
        $query->withAuthors($user_phids);
        break;
      case 'subscribed':
        $query->withSubscribers($user_phids);
        break;
      case 'triage':
        $query->withOwners($user_phids);
        $query->withPriority(ManiphestTaskPriority::PRIORITY_TRIAGE);
        break;
      case 'alltriage':
        $query->withPriority(ManiphestTaskPriority::PRIORITY_TRIAGE);
        break;
      case 'all':
        break;
      case 'projecttriage':
        $query->withPriority(ManiphestTaskPriority::PRIORITY_TRIAGE);
        $any_project = true;
        break;
      case 'projectall':
        $any_project = true;
        break;
    }

    $query->withAnyProject($any_project);

    $order_map = array(
      'priority'  => ManiphestTaskQuery::ORDER_PRIORITY,
      'created'   => ManiphestTaskQuery::ORDER_CREATED,
    );
    $query->setOrderBy(
      idx(
        $order_map,
        $search_query->getParameter('order'),
        ManiphestTaskQuery::ORDER_MODIFIED));

    $group_map = array(
      'priority'  => ManiphestTaskQuery::GROUP_PRIORITY,
      'owner'     => ManiphestTaskQuery::GROUP_OWNER,
      'status'    => ManiphestTaskQuery::GROUP_STATUS,
      'project'   => ManiphestTaskQuery::GROUP_PROJECT,
    );
    $query->setGroupBy(
      idx(
        $group_map,
        $search_query->getParameter('group'),
        ManiphestTaskQuery::GROUP_NONE));

    $query->setCalculateRows(true);
    $query->setLimit($search_query->getParameter('limit'));
    $query->setOffset($search_query->getParameter('offset'));

    $data = $query->execute();
    $total_row_count = $query->getRowCount();

    $project_group_phids = array();
    if ($search_query->getParameter('group') == 'project') {
      foreach ($data as $task) {
        foreach ($task->getProjectPHIDs() as $phid) {
          $project_group_phids[] = $phid;
        }
      }
    }

    $handle_phids = mpull($data, 'getOwnerPHID');
    $handle_phids = array_merge(
      $handle_phids,
      $project_phids,
      $user_phids,
      $xproject_phids,
      $owner_phids,
      $author_phids,
      $project_group_phids,
      array_mergev(mpull($data, 'getProjectPHIDs')));
    $handles = id(new PhabricatorObjectHandleData($handle_phids))
      ->loadHandles();

    switch ($search_query->getParameter('group')) {
      case 'priority':
        $data = mgroup($data, 'getPriority');

        // If we have invalid priorities, they'll all map to "???". Merge
        // arrays to prevent them from overwriting each other.

        $out = array();
        foreach ($data as $pri => $tasks) {
          $out[ManiphestTaskPriority::getTaskPriorityName($pri)][] = $tasks;
        }
        foreach ($out as $pri => $tasks) {
          $out[$pri] = array_mergev($tasks);
        }
        $data = $out;

        break;
      case 'status':
        $data = mgroup($data, 'getStatus');

        $out = array();
        foreach ($data as $status => $tasks) {
          $out[ManiphestTaskStatus::getTaskStatusFullName($status)] = $tasks;
        }

        $data = $out;
        break;
      case 'owner':
        $data = mgroup($data, 'getOwnerPHID');

        $out = array();
        foreach ($data as $phid => $tasks) {
          if ($phid) {
            $out[$handles[$phid]->getFullName()] = $tasks;
          } else {
            $out['Unassigned'] = $tasks;
          }
        }

        $data = $out;
        ksort($data);

        // Move "Unassigned" to the top of the list.
        if (isset($data['Unassigned'])) {
          $data = array('Unassigned' => $out['Unassigned']) + $out;
        }
        break;
      case 'project':
        $grouped = array();
        foreach ($data as $task) {
          $phids = $task->getProjectPHIDs();
          if ($project_phids && $any_project !== true) {
            // If the user is filtering on "Bugs", don't show a "Bugs" group
            // with every result since that's silly (the query also does this
            // on the backend).
            $phids = array_diff($phids, $project_phids);
          }
          if ($phids) {
            foreach ($phids as $phid) {
              $grouped[$handles[$phid]->getName()][$task->getID()] = $task;
            }
          } else {
            $grouped['No Project'][$task->getID()] = $task;
          }
        }
        $data = $grouped;
        ksort($data);

        // Move "No Project" to the end of the list.
        if (isset($data['No Project'])) {
          $noproject = $data['No Project'];
          unset($data['No Project']);
          $data += array('No Project' => $noproject);
        }
        break;
      default:
        $data = array(
          'Tasks' => $data,
        );
        break;
    }

    return array($data, $handles, $total_row_count);
  }

  private function renderBatchEditor(PhabricatorSearchQuery $search_query) {
    Javelin::initBehavior(
      'maniphest-batch-selector',
      array(
        'selectAll'   => 'batch-select-all',
        'selectNone'  => 'batch-select-none',
        'submit'      => 'batch-select-submit',
        'status'      => 'batch-select-status-cell',
      ));

    $select_all = javelin_render_tag(
      'a',
      array(
        'href'        => '#',
        'mustcapture' => true,
        'class'       => 'grey button',
        'id'          => 'batch-select-all',
      ),
      'Select All');

    $select_none = javelin_render_tag(
      'a',
      array(
        'href'        => '#',
        'mustcapture' => true,
        'class'       => 'grey button',
        'id'          => 'batch-select-none',
      ),
      'Clear Selection');

    $submit = phutil_render_tag(
      'button',
      array(
        'id'          => 'batch-select-submit',
        'disabled'    => 'disabled',
        'class'       => 'disabled',
      ),
      'Batch Edit Selected Tasks &raquo;');

    $export = javelin_render_tag(
      'a',
      array(
        'href' => '/maniphest/export/'.$search_query->getQueryKey().'/',
        'class' => 'grey button',
      ),
      'Export Tasks to Excel...');

    return
      '<div class="maniphest-batch-editor">'.
        '<div class="batch-editor-header">Batch Task Editor</div>'.
        '<table class="maniphest-batch-editor-layout">'.
          '<tr>'.
            '<td>'.
              $select_all.
              $select_none.
            '</td>'.
            '<td>'.
              $export.
            '</td>'.
            '<td id="batch-select-status-cell">'.
              '0 Selected Tasks'.
            '</td>'.
            '<td class="batch-select-submit-cell">'.$submit.'</td>'.
          '</tr>'.
        '</table>'.
      '</table>';
  }

  private function buildQueryFromRequest() {
    $request = $this->getRequest();
    $user = $request->getUser();

    $status   = $this->getStatusValueFromRequest();
    $group    = $this->getGroupValueFromRequest();
    $order    = $this->getOrderValueFromRequest();

    $user_phids = $request->getStrList(
      'users',
      array($user->getPHID()));

    if ($this->view == 'projecttriage' || $this->view == 'projectall') {
      $project_query = new PhabricatorProjectQuery();
      $project_query->setMembers($user_phids);
      $projects = $project_query->execute();
      $project_phids = mpull($projects, 'getPHID');
    } else {
      $project_phids = $request->getStrList('projects');
    }

    $exclude_project_phids = $request->getStrList('xprojects');
    $task_ids = $request->getStrList('tasks');
    $owner_phids = $request->getStrList('owners');
    $author_phids = $request->getStrList('authors');

    $page = $request->getInt('offset');
    $page_size = self::DEFAULT_PAGE_SIZE;

    $query = new PhabricatorSearchQuery();
    $query->setQuery('<<maniphest>>');
    $query->setParameters(
      array(
        'view'                => $this->view,
        'userPHIDs'           => $user_phids,
        'projectPHIDs'        => $project_phids,
        'excludeProjectPHIDs' => $exclude_project_phids,
        'ownerPHIDs'          => $owner_phids,
        'authorPHIDs'         => $author_phids,
        'taskIDs'             => $task_ids,
        'group'               => $group,
        'order'               => $order,
        'offset'              => $page,
        'limit'               => $page_size,
        'status'              => $status,
      ));

    $unguarded = AphrontWriteGuard::beginScopedUnguardedWrites();
    $query->save();
    unset($unguarded);

    return $query;
  }

/* -(  Toggle Button Controls  )---------------------------------------------

  These are a giant mess since we have several different values: the request
  key (GET param used in requests), the request value (short names used in
  requests to keep URIs readable), and the query value (complex value stored in
  the query).

*/

  private function getStatusValueFromRequest() {
    $map = $this->getStatusMap();
    $val = $this->getRequest()->getStr($this->getStatusRequestKey());
    return idx($map, $val, head($map));
  }

  private function getGroupValueFromRequest() {
    $map = $this->getGroupMap();
    $val = $this->getRequest()->getStr($this->getGroupRequestKey());
    return idx($map, $val, head($map));
  }

  private function getOrderValueFromRequest() {
    $map = $this->getOrderMap();
    $val = $this->getRequest()->getStr($this->getOrderRequestKey());
    return idx($map, $val, head($map));
  }

  private function getStatusRequestKey() {
    return 's';
  }

  private function getGroupRequestKey() {
    return 'g';
  }

  private function getOrderRequestKey() {
    return 'o';
  }

  private function getStatusRequestValue($value) {
    return array_search($value, $this->getStatusMap());
  }

  private function getGroupRequestValue($value) {
    return array_search($value, $this->getGroupMap());
  }

  private function getOrderRequestValue($value) {
    return array_search($value, $this->getOrderMap());
  }

  private function getStatusMap() {
    return array(
      'o'   => array(
        'open' => true,
      ),
      'c'   => array(
        'closed' => true,
      ),
      'oc'  => array(
        'open' => true,
        'closed' => true,
      ),
    );
  }

  private function getGroupMap() {
    return array(
      'p' => 'priority',
      'o' => 'owner',
      's' => 'status',
      'j' => 'project',
      'n' => 'none',
    );
  }

  private function getOrderMap() {
    return array(
      'p' => 'priority',
      'u' => 'updated',
      'c' => 'created',
    );
  }

  private function getStatusButtonMap() {
    return array(
      'o'   => 'Open',
      'c'   => 'Closed',
      'oc'  => 'All',
    );
  }

  private function getGroupButtonMap() {
    return array(
      'p' => 'Priority',
      'o' => 'Owner',
      's' => 'Status',
      'j' => 'Project',
      'n' => 'None',
    );
  }

  private function getOrderButtonMap() {
    return array(
      'p' => 'Priority',
      'u' => 'Updated',
      'c' => 'Created',
    );
  }

  public function renderStatusControl($value) {
    $request = $this->getRequest();
    return id(new AphrontFormToggleButtonsControl())
      ->setLabel('Status')
      ->setValue($this->getStatusRequestValue($value))
      ->setBaseURI($request->getRequestURI(), $this->getStatusRequestKey())
      ->setButtons($this->getStatusButtonMap());
  }

  public function renderOrderControl($value) {
    $request = $this->getRequest();
    return id(new AphrontFormToggleButtonsControl())
      ->setLabel('Order')
      ->setValue($this->getOrderRequestValue($value))
      ->setBaseURI($request->getRequestURI(), $this->getOrderRequestKey())
      ->setButtons($this->getOrderButtonMap());
  }

  public function renderGroupControl($value) {
    $request = $this->getRequest();
    return id(new AphrontFormToggleButtonsControl())
      ->setLabel('Group')
      ->setValue($this->getGroupRequestValue($value))
      ->setBaseURI($request->getRequestURI(), $this->getGroupRequestKey())
      ->setButtons($this->getGroupButtonMap());
  }

}
