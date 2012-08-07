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

final class PhabricatorDirectoryMainController
  extends PhabricatorDirectoryController {

  private $filter;
  private $subfilter;

  public function willProcessRequest(array $data) {
    $this->filter = idx($data, 'filter');
    $this->subfilter = idx($data, 'subfilter');
  }

  public function processRequest() {
    $user = $this->getRequest()->getUser();

    $nav = $this->buildNav();
    $this->filter = $nav->selectFilter($this->filter, 'home');

    switch ($this->filter) {
      case 'jump':
      case 'apps':
        break;
      case 'home':
      case 'feed':
        $project_query = new PhabricatorProjectQuery();
        $project_query->setMembers(array($user->getPHID()));
        $projects = $project_query->execute();
        break;
      default:
        throw new Exception("Unknown filter '{$this->filter}'!");
    }

    switch ($this->filter) {
      case 'feed':
        return $this->buildFeedResponse($nav, $projects);
      case 'jump':
        return $this->buildJumpResponse($nav);
      case 'apps':
        return $this->buildAppsResponse($nav);
      default:
        return $this->buildMainResponse($nav, $projects);
    }

  }

  private function buildMainResponse($nav, array $projects) {
    assert_instances_of($projects, 'PhabricatorProject');

    if (PhabricatorEnv::getEnvConfig('maniphest.enabled')) {
      $unbreak_panel = $this->buildUnbreakNowPanel();
      $triage_panel = $this->buildNeedsTriagePanel($projects);
      $tasks_panel = $this->buildTasksPanel();
    } else {
      $unbreak_panel = null;
      $triage_panel = null;
      $tasks_panel = null;
    }

    $flagged_panel = $this->buildFlaggedPanel();

    $jump_panel = $this->buildJumpPanel();
    $revision_panel = $this->buildRevisionPanel();
    $app_panel = $this->buildAppPanel();
    $audit_panel = $this->buildAuditPanel();
    $commit_panel = $this->buildCommitPanel();

    $content = array(
      $app_panel,
      $jump_panel,
      $unbreak_panel,
      $triage_panel,
      $revision_panel,
      $tasks_panel,
      $flagged_panel,
      $audit_panel,
      $commit_panel,
    );

    $nav->appendChild($content);

    return $this->buildStandardPageResponse(
      $nav,
      array(
        'title' => 'Phabricator',
      ));
  }

  private function buildJumpResponse($nav) {
    $request = $this->getRequest();

    if ($request->isFormPost()) {
      $jump = $request->getStr('jump');

      $response = PhabricatorJumpNavHandler::jumpPostResponse($jump);
      if ($response) {
        return $response;
      } else {
        $query = new PhabricatorSearchQuery();
        $query->setQuery($jump);
        $query->save();

        return id(new AphrontRedirectResponse())
          ->setURI('/search/'.$query->getQueryKey().'/');
      }
    }


    $nav->appendChild($this->buildJumpPanel());
    return $this->buildStandardPageResponse(
      $nav,
      array(
        'title' => 'Jump Nav',
      ));
  }

  private function buildFeedResponse($nav, array $projects) {
    assert_instances_of($projects, 'PhabricatorProject');

    $subnav = new AphrontSideNavFilterView();
    $subnav->setBaseURI(new PhutilURI('/feed/'));

    $subnav->addFilter('all',       'All Activity', '/feed/');
    $subnav->addFilter('projects',  'My Projects');

    $nav->appendChild($subnav);

    $filter = $subnav->selectFilter($this->subfilter, 'all');

    switch ($filter) {
      case 'all':
        $view = $this->buildFeedView(array());
        break;
      case 'projects':
        if ($projects) {
          $phids = mpull($projects, 'getPHID');
          $view = $this->buildFeedView($phids);
        } else {
          $view = new AphrontErrorView();
          $view->setSeverity(AphrontErrorView::SEVERITY_NODATA);
          $view->setTitle('No Projects');
          $view->appendChild('You have not joined any projects.');
        }
        break;
    }

    $subnav->appendChild($view);

    return $this->buildStandardPageResponse(
      $nav,
      array(
        'title' => 'Feed',
      ));
  }

  private function buildUnbreakNowPanel() {
    $user = $this->getRequest()->getUser();
    $user_phid = $user->getPHID();

    $task_query = new ManiphestTaskQuery();
    $task_query->withStatus(ManiphestTaskQuery::STATUS_OPEN);
    $task_query->withPriority(ManiphestTaskPriority::PRIORITY_UNBREAK_NOW);
    $task_query->setLimit(10);

    $tasks = $task_query->execute();

    if (!$tasks) {
      return $this->renderMiniPanel(
        'No "Unbreak Now!" Tasks',
        'Nothing appears to be critically broken right now.');
    }

    $panel = new AphrontPanelView();
    $panel->setHeader('Unbreak Now!');
    $panel->setCaption('Open tasks with "Unbreak Now!" priority.');
    $panel->addButton(
      phutil_render_tag(
        'a',
        array(
          'href' => '/maniphest/view/all/',
          'class' => 'grey button',
        ),
        "View All Unbreak Now \xC2\xBB"));

    $panel->appendChild($this->buildTaskListView($tasks));

    return $panel;
  }

  private function buildFlaggedPanel() {
    $user = $this->getRequest()->getUser();

    $flag_query = id(new PhabricatorFlagQuery())
      ->withOwnerPHIDs(array($user->getPHID()))
      ->needHandles(true)
      ->setLimit(10);

    $flags = $flag_query->execute();

    if (!$flags) {
      return $this->renderMiniPanel(
        'No Flags',
        "You haven't flagged anything.");
    }

    $panel = new AphrontPanelView();
    $panel->setHeader('Flagged Objects');
    $panel->setCaption("Objects you've flagged.");

    $flag_view = new PhabricatorFlagListView();
    $flag_view->setFlags($flags);
    $flag_view->setUser($user);
    $panel->appendChild($flag_view);

    $panel->addButton(
      phutil_render_tag(
        'a',
        array(
          'href'  => '/flag/',
          'class' => 'grey button',
        ),
        "View All Flags \xC2\xBB"));

    return $panel;
  }

  private function buildNeedsTriagePanel(array $projects) {
    assert_instances_of($projects, 'PhabricatorProject');

    $user = $this->getRequest()->getUser();
    $user_phid = $user->getPHID();

    if ($projects) {
      $task_query = new ManiphestTaskQuery();
      $task_query->withStatus(ManiphestTaskQuery::STATUS_OPEN);
      $task_query->withPriority(ManiphestTaskPriority::PRIORITY_TRIAGE);
      $task_query->withProjects(mpull($projects, 'getPHID'));
      $task_query->withAnyProject(true);
      $task_query->setLimit(10);
      $tasks = $task_query->execute();
    } else {
      $tasks = array();
    }

    if (!$tasks) {
      return $this->renderMiniPanel(
        'No "Needs Triage" Tasks',
        'No tasks in <a href="/project/">projects you are a member of</a> '.
        'need triage.');
    }

    $panel = new AphrontPanelView();
    $panel->setHeader('Needs Triage');
    $panel->setCaption(
      'Open tasks with "Needs Triage" priority in '.
      '<a href="/project/">projects you are a member of</a>.');

    $panel->addButton(
      phutil_render_tag(
        'a',
        array(
          // TODO: This should filter to just your projects' need-triage
          // tasks?
          'href' => '/maniphest/view/projecttriage/',
          'class' => 'grey button',
        ),
        "View All Triage \xC2\xBB"));
    $panel->appendChild($this->buildTaskListView($tasks));

    return $panel;
  }

  private function buildRevisionPanel() {
    $user = $this->getRequest()->getUser();
    $user_phid = $user->getPHID();

    $revision_query = new DifferentialRevisionQuery();
    $revision_query->withStatus(DifferentialRevisionQuery::STATUS_OPEN);
    $revision_query->withResponsibleUsers(array($user_phid));
    $revision_query->needRelationships(true);

    // NOTE: We need to unlimit this query to hit the responsible user
    // fast-path.
    $revision_query->setLimit(null);
    $revisions = $revision_query->execute();

    list($active, $waiting) = DifferentialRevisionQuery::splitResponsible(
      $revisions,
      $user_phid);

    if (!$active) {
      return $this->renderMiniPanel(
        'No Waiting Revisions',
        'No revisions are waiting on you.');
    }

    $panel = new AphrontPanelView();
    $panel->setHeader('Revisions Waiting on You');
    $panel->setCaption('Revisions waiting for you for review or commit.');

    $panel->addButton(
      phutil_render_tag(
        'a',
        array(
          'href' => '/differential/',
          'class' => 'button grey',
        ),
        "View Active Revisions \xC2\xBB"));

    $fields =

    $revision_view = id(new DifferentialRevisionListView())
      ->setRevisions($active)
      ->setFields(DifferentialRevisionListView::getDefaultFields())
      ->setUser($user);
    $phids = array_merge(
      array($user_phid),
      $revision_view->getRequiredHandlePHIDs());
    $handles = id(new PhabricatorObjectHandleData($phids))->loadHandles();

    $revision_view->setHandles($handles);

    $panel->appendChild($revision_view);

    return $panel;
  }

  private function buildTasksPanel() {
    $user = $this->getRequest()->getUser();
    $user_phid = $user->getPHID();

    $task_query = new ManiphestTaskQuery();
    $task_query->withStatus(ManiphestTaskQuery::STATUS_OPEN);
    $task_query->setGroupBy(ManiphestTaskQuery::GROUP_PRIORITY);
    $task_query->withOwners(array($user_phid));
    $task_query->setLimit(10);

    $tasks = $task_query->execute();


    if (!$tasks) {
      return $this->renderMiniPanel(
        'No Assigned Tasks',
        'You have no assigned tasks.');
    }

    $panel = new AphrontPanelView();
    $panel->setHeader('Assigned Tasks');
    $panel->setCaption('Tasks assigned to you.');

    $panel->addButton(
      phutil_render_tag(
        'a',
        array(
          'href' => '/maniphest/',
          'class' => 'button grey',
        ),
        "View Active Tasks \xC2\xBB"));
    $panel->appendChild($this->buildTaskListView($tasks));

    return $panel;
  }

  private function buildTaskListView(array $tasks) {
    assert_instances_of($tasks, 'ManiphestTask');
    $user = $this->getRequest()->getUser();

    $phids = array_merge(
      array_filter(mpull($tasks, 'getOwnerPHID')),
      array_mergev(mpull($tasks, 'getProjectPHIDs')));

    $handles = id(new PhabricatorObjectHandleData($phids))->loadHandles();

    $view = new ManiphestTaskListView();
    $view->setTasks($tasks);
    $view->setUser($user);
    $view->setHandles($handles);

    return $view;
  }

  private function buildFeedView(array $phids) {
    $request = $this->getRequest();
    $user = $request->getUser();
    $user_phid = $user->getPHID();

    $feed_query = new PhabricatorFeedQuery();
    if ($phids) {
      $feed_query->setFilterPHIDs($phids);
    }

    // TODO: All this limit stuff should probably be consolidated into the
    // feed query?

    $old_link = null;
    $new_link = null;

    $feed_query->setAfter($request->getStr('after'));
    $feed_query->setBefore($request->getStr('before'));
    $limit = 500;

    // Grab one more story than we intend to display so we can figure out
    // if we need to render an "Older Posts" link or not (with reasonable
    // accuracy, at least).
    $feed_query->setLimit($limit + 1);
    $feed = $feed_query->execute();
    $extra_row = (count($feed) == $limit + 1);

    $have_new = ($request->getStr('before')) ||
                ($request->getStr('after') && $extra_row);

    $have_old = ($request->getStr('after')) ||
                ($request->getStr('before') && $extra_row) ||
                (!$request->getStr('before') &&
                 !$request->getStr('after') &&
                 $extra_row);
    $feed = array_slice($feed, 0, $limit, $preserve_keys = true);

    if ($have_old) {
      $old_link = phutil_render_tag(
        'a',
        array(
          'href' => '?before='.end($feed)->getChronologicalKey(),
          'class' => 'phabricator-feed-older-link',
        ),
        "Older Stories \xC2\xBB");
    }
    if ($have_new) {
      $new_link = phutil_render_tag(
        'a',
        array(
          'href' => '?after='.reset($feed)->getChronologicalKey(),
          'class' => 'phabricator-feed-newer-link',
        ),
        "\xC2\xAB Newer Stories");
    }

    $builder = new PhabricatorFeedBuilder($feed);
    $builder->setUser($user);
    $feed_view = $builder->buildView();

    return
      '<div style="padding: 1em 3em;">'.
        '<div style="margin: 0 1em;">'.
          '<h1 style="font-size: 18px; '.
                     'border-bottom: 1px solid #aaaaaa; '.
                     'padding: 0;">Feed</h1>'.
        '</div>'.
        $feed_view->render().
        '<div class="phabricator-feed-frame">'.
          $new_link.
          $old_link.
        '</div>'.
      '</div>';
  }

  private function buildJumpPanel() {
    $request = $this->getRequest();
    $user = $request->getUser();

    $uniq_id = celerity_generate_unique_node_id();

    Javelin::initBehavior(
      'phabricator-autofocus',
      array(
        'id' => $uniq_id,
      ));

    require_celerity_resource('phabricator-jump-nav');

    $doc_href = PhabricatorEnv::getDocLink('article/Jump_Nav_User_Guide.html');
    $doc_link = phutil_render_tag(
      'a',
      array(
        'href' => $doc_href,
      ),
      'Jump Nav User Guide');

    $jump_input = phutil_render_tag(
      'input',
      array(
        'type'  => 'text',
        'class' => 'phabricator-jump-nav',
        'name'  => 'jump',
        'id'    => $uniq_id,
      ));
    $jump_caption = phutil_render_tag(
      'p',
      array(
        'class' => 'phabricator-jump-nav-caption',
      ),
      'Enter the name of an object like <tt>D123</tt> to quickly jump to '.
      'it. See '.$doc_link.' or type <tt>help</tt>.');

    $panel = new AphrontPanelView();
    $panel->addClass('aphront-unpadded-panel-view');
    $panel->appendChild(
      phabricator_render_form(
        $user,
        array(
          'action' => '/jump/',
          'method' => 'POST',
          'class'  => 'phabricator-jump-nav-form',
        ),
        $jump_input.
        $jump_caption));

    return $panel;
  }

  private function buildAppPanel() {
    require_celerity_resource('phabricator-app-buttons-css');

    $nav_buttons = array();

    $nav_buttons[] = array(
      'Differential',
      '/differential/',
      'differential',
      'Code Reviews');

    if (PhabricatorEnv::getEnvConfig('maniphest.enabled')) {
      $nav_buttons[] = array(
        'Maniphest',
        '/maniphest/',
        'maniphest',
        'Tasks');
      $nav_buttons[] = array(
        'Create Task',
        '/maniphest/task/create/',
        'create-task');
    }

    $nav_buttons[] = array(
      'Upload File',
      '/file/',
      'upload-file',
      'Share Files');
    $nav_buttons[] = array(
      'Create Paste',
      '/paste/',
      'create-paste',
      'Share Text');


    if (PhabricatorEnv::getEnvConfig('phriction.enabled')) {
      $nav_buttons[] = array(
        'Phriction',
        '/w/',
        'phriction',
        'Browse Wiki');
    }

    $nav_buttons[] = array(
      'Diffusion',
      '/diffusion/',
      'diffusion',
      'Browse Code');

    $nav_buttons[] = array(
      'Audit',
      '/audit/',
      'audit',
      'Audit Code');

    $view = new AphrontNullView();
    $view->appendChild('<div class="phabricator-app-buttons">');
    foreach ($nav_buttons as $info) {
      // Subtitle is optional.
      list($name, $uri, $icon, $subtitle) = array_merge($info, array(null));

      if ($subtitle) {
        $subtitle =
          '<div class="phabricator-app-subtitle">'.
            phutil_escape_html($subtitle).
          '</div>';
      }

      $button = phutil_render_tag(
        'a',
        array(
          'href'  => $uri,
          'class' => 'app-button icon-'.$icon,
        ),
        phutil_render_tag(
          'div',
          array(
            'class' => 'app-icon icon-'.$icon,
          ),
          ''));
      $caption = phutil_render_tag(
        'a',
        array(
          'href' => $uri,
          'class' => 'phabricator-button-caption',
        ),
        phutil_escape_html($name).$subtitle);

      $view->appendChild(
        '<div class="phabricator-app-button">'.
          $button.
          $caption.
        '</div>');
    }
    $view->appendChild('<div style="clear: both;"></div></div>');

    return $view;
  }

  private function renderMiniPanel($title, $body) {
    $panel = new AphrontMiniPanelView();
    $panel->appendChild(
      phutil_render_tag(
        'p',
        array(
        ),
        '<strong>'.$title.':</strong> '.$body));
    return $panel;
  }

  public function buildAuditPanel() {
    $request = $this->getRequest();
    $user = $request->getUser();

    $phids = PhabricatorAuditCommentEditor::loadAuditPHIDsForUser($user);

    $query = new PhabricatorAuditQuery();
    $query->withAuditorPHIDs($phids);
    $query->withStatus(PhabricatorAuditQuery::STATUS_OPEN);
    $query->withAwaitingUser($user);
    $query->needCommitData(true);
    $query->setLimit(10);

    $audits = $query->execute();
    $commits = $query->getCommits();

    if (!$audits) {
      return $this->renderMinipanel(
        'No Audits',
        'No commits are waiting for you to audit them.');
    }

    $view = new PhabricatorAuditListView();
    $view->setAudits($audits);
    $view->setCommits($commits);

    $phids = $view->getRequiredHandlePHIDs();
    $handles = id(new PhabricatorObjectHandleData($phids))->loadHandles();
    $view->setHandles($handles);

    $panel = new AphrontPanelView();
    $panel->setHeader('Audits');
    $panel->setCaption('Commits awaiting your audit.');
    $panel->appendChild($view);
      $panel->addButton(
        phutil_render_tag(
          'a',
          array(
            'href' => '/audit/',
            'class' => 'button grey',
          ),
          "View Active Audits \xC2\xBB"));

    return $panel;
  }

  public function buildCommitPanel() {
    $request = $this->getRequest();
    $user = $request->getUser();

    $phids = array($user->getPHID());

    $query = new PhabricatorAuditCommitQuery();
    $query->withAuthorPHIDs($phids);
    $query->withStatus(PhabricatorAuditQuery::STATUS_OPEN);
    $query->needCommitData(true);
    $query->setLimit(10);

    $commits = $query->execute();

    if (!$commits) {
      return $this->renderMinipanel(
        'No Problem Commits',
        'No one has raised concerns with your commits.');
    }

    $view = new PhabricatorAuditCommitListView();
    $view->setCommits($commits);
    $view->setUser($user);

    $phids = $view->getRequiredHandlePHIDs();
    $handles = id(new PhabricatorObjectHandleData($phids))->loadHandles();
    $view->setHandles($handles);

    $panel = new AphrontPanelView();
    $panel->setHeader('Problem Commits');
    $panel->setCaption('Commits which auditors have raised concerns about.');
    $panel->appendChild($view);
      $panel->addButton(
        phutil_render_tag(
          'a',
          array(
            'href' => '/audit/',
            'class' => 'button grey',
          ),
          "View Problem Commits \xC2\xBB"));

    return $panel;
  }

  public function buildAppsResponse(AphrontSideNavFilterView $nav) {
    $user = $this->getRequest()->getUser();

    $apps = array(
      array(
        '/repository/',
        'Repositories',
        'Configure tracked source code repositories.',
      ),
      array(
        '/herald/',
        'Herald',
        'Create notification rules. Watch for danger!',
      ),
      array(
        '/file/',
        'Files',
        'Upload and download files. Blob store for Pokemon pictures.',
      ),
      array(
        '/project/',
        'Projects',
        'Group stuff into big piles.',
      ),
      array(
        '/vote/',
        'Slowvote',
        'Create polls. Design by committee.',
      ),
      array(
        '/countdown/',
        'Countdown',
        'Count down to events. Utilize the full capabilities of your ALU.',
      ),
      array(
        '/people/',
        'People',
        'User directory. Sort of a social utility.',
      ),
      array(
        '/owners/',
        'Owners',
        'Keep track of who owns code. Adopt today!',
      ),
      array(
        '/conduit/',
        'Conduit API Console',
        'Web console for Conduit API.',
      ),
      array(
        '/daemon/',
        'Daemon Console',
        'Offline process management.',
      ),
      array(
        '/mail/',
        'MetaMTA',
        'Manage mail delivery. Yo dawg, we heard you like MTAs.',
        array(
          'admin' => true,
        ),
      ),
      array(
        '/phid/',
        'PHID Manager',
        'Debugging tool for PHIDs.',
      ),
      array(
        '/xhpast/',
        'XHPAST',
        'Web interface to PHP AST tool. Lex XHP AST & CTS FYI, LOL.',
      ),
      array(
        'http://www.phabricator.com/docs/phabricator/',
        'Phabricator Ducks',
        'Oops, that should say "Docs".',
        array(
          'new' => true,
        ),
      ),
      array(
        'http://www.phabricator.com/docs/arcanist/',
        'Arcanist Docs',
        'Words have never been so finely crafted.',
        array(
          'new' => true,
        ),
      ),
      array(
        'http://www.phabricator.com/docs/libphutil/',
        'libphutil Docs',
        'Soothing prose; seductive poetry.',
        array(
          'new' => true,
        ),
      ),
      array(
        'http://www.phabricator.com/docs/javelin/',
        'Javelin Docs',
        'O, what noble scribe hath penned these words?',
        array(
          'new' => true,
        ),
      ),
      array(
        '/uiexample/',
        'UI Examples',
        'Phabricator UI elements. A gallery of modern art.',
        array(
          'new' => true,
        ),
      ),
    );

    $out = array();
    foreach ($apps as $app) {
      if (empty($app[3])) {
        $app[3] = array();
      }
      $app[3] += array(
        'admin' => false,
        'new'   => false,
      );
      list($href, $name, $desc, $options) = $app;

      if ($options['admin'] && !$user->getIsAdmin()) {
        continue;
      }

      $link = phutil_render_tag(
        'a',
        array(
          'href'    => $href,
          'target'  => $options['new'] ? '_blank' : null,
        ),
        phutil_escape_html($name));



      $out[] =
        '<div class="aphront-directory-item">'.
          '<h1>'.$link.'</h1>'.
          '<p>'.phutil_escape_html($desc).'</p>'.
        '</div>';
    }

    require_celerity_resource('phabricator-directory-css');

    $out =
      '<div class="aphront-directory-list">'.
        implode("\n", $out).
      '</div>';

    $nav->appendChild($out);

    return $this->buildStandardPageResponse(
      $nav,
      array(
        'title' => 'More Stuff',
      ));
  }

}
