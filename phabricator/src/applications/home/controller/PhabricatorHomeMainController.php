<?php

final class PhabricatorHomeMainController
  extends PhabricatorHomeController {

  private $filter;
  private $minipanels = array();

  public function shouldAllowPublic() {
    return true;
  }

  public function willProcessRequest(array $data) {
    $this->filter = idx($data, 'filter');
  }

  public function processRequest() {
    $user = $this->getRequest()->getUser();

    if ($this->filter == 'jump') {
      return $this->buildJumpResponse();
    }

    $nav = $this->buildNav();

    $project_query = new PhabricatorProjectQuery();
    $project_query->setViewer($user);
    $project_query->withMemberPHIDs(array($user->getPHID()));
    $projects = $project_query->execute();

    return $this->buildMainResponse($nav, $projects);
  }

  private function buildMainResponse($nav, array $projects) {
    assert_instances_of($projects, 'PhabricatorProject');
    $viewer = $this->getRequest()->getUser();

    $has_maniphest = PhabricatorApplication::isClassInstalledForViewer(
      'PhabricatorApplicationManiphest',
      $viewer);

    $has_audit = PhabricatorApplication::isClassInstalledForViewer(
      'PhabricatorApplicationAudit',
      $viewer);

    $has_differential = PhabricatorApplication::isClassInstalledForViewer(
      'PhabricatorApplicationDifferential',
      $viewer);

    if ($has_maniphest) {
      $unbreak_panel = $this->buildUnbreakNowPanel();
      $triage_panel = $this->buildNeedsTriagePanel($projects);
      $tasks_panel = $this->buildTasksPanel();
    } else {
      $unbreak_panel = null;
      $triage_panel = null;
      $tasks_panel = null;
    }

    if ($has_audit) {
      $audit_panel = $this->buildAuditPanel();
      $commit_panel = $this->buildCommitPanel();
    } else {
      $audit_panel = null;
      $commit_panel = null;
    }

    if (PhabricatorEnv::getEnvConfig('welcome.html') !== null) {
      $welcome_panel = $this->buildWelcomePanel();
    } else {
      $welcome_panel = null;
    }

    $jump_panel = $this->buildJumpPanel();

    if ($has_differential) {
      $revision_panel = $this->buildRevisionPanel();
    } else {
      $revision_panel = null;
    }

    $content = array(
      $jump_panel,
      $welcome_panel,
      $unbreak_panel,
      $triage_panel,
      $revision_panel,
      $tasks_panel,
      $audit_panel,
      $commit_panel,
      $this->minipanels,
    );

    $user = $this->getRequest()->getUser();
    $nav->appendChild($content);
    $nav->appendChild(id(new PhabricatorGlobalUploadTargetView())
      ->setUser($user));

    return $this->buildApplicationPage(
      $nav,
      array(
        'title' => 'Phabricator',
        'device' => true,
      ));
  }

  private function buildJumpResponse() {
    $request = $this->getRequest();
    $jump = $request->getStr('jump');

    $response = PhabricatorJumpNavHandler::getJumpResponse(
      $request->getUser(),
      $jump);

    if ($response) {
      return $response;
    } else if ($request->isFormPost()) {
      $uri = new PhutilURI('/search/');
      $uri->setQueryParam('query', $jump);
      $uri->setQueryParam('search:primary', 'true');

      return id(new AphrontRedirectResponse())->setURI((string)$uri);
    } else {
      return id(new AphrontRedirectResponse())->setURI('/');
    }
  }

  private function buildUnbreakNowPanel() {
    $unbreak_now = PhabricatorEnv::getEnvConfig(
      'maniphest.priorities.unbreak-now');
    if (!$unbreak_now) {
      return null;
    }

    $user = $this->getRequest()->getUser();

    $task_query = id(new ManiphestTaskQuery())
      ->setViewer($user)
      ->withStatuses(ManiphestTaskStatus::getOpenStatusConstants())
      ->withPriorities(array($unbreak_now))
      ->setLimit(10);

    $tasks = $task_query->execute();

    if (!$tasks) {
      return $this->renderMiniPanel(
        'No "Unbreak Now!" Tasks',
        'Nothing appears to be critically broken right now.');
    }

    $href = '/maniphest/?statuses[]=0&priorities[]='.$unbreak_now.'#R';
    $title = pht('Unbreak Now!');
    $panel = new AphrontPanelView();
    $panel->setHeader($this->renderSectionHeader($title, $href));
    $panel->appendChild($this->buildTaskListView($tasks));
    $panel->setNoBackground();

    return $panel;
  }

  private function buildNeedsTriagePanel(array $projects) {
    assert_instances_of($projects, 'PhabricatorProject');

    $needs_triage = PhabricatorEnv::getEnvConfig(
      'maniphest.priorities.needs-triage');
    if (!$needs_triage) {
      return null;
    }

    $user = $this->getRequest()->getUser();
    if (!$user->isLoggedIn()) {
      return null;
    }

    if ($projects) {
      $task_query = id(new ManiphestTaskQuery())
        ->setViewer($user)
        ->withStatuses(ManiphestTaskStatus::getOpenStatusConstants())
        ->withPriorities(array($needs_triage))
        ->withAnyProjects(mpull($projects, 'getPHID'))
        ->setLimit(10);
      $tasks = $task_query->execute();
    } else {
      $tasks = array();
    }

    if (!$tasks) {
      return $this->renderMiniPanel(
        'No "Needs Triage" Tasks',
        hsprintf(
          'No tasks in <a href="/project/">projects you are a member of</a> '.
          'need triage.'));
    }

    $title = pht('Needs Triage');
    $href = '/maniphest/?statuses[]=0&priorities[]='.$needs_triage.
                    '&userProjects[]='.$user->getPHID().'#R';
    $panel = new AphrontPanelView();
    $panel->setHeader($this->renderSectionHeader($title, $href));
    $panel->appendChild($this->buildTaskListView($tasks));
    $panel->setNoBackground();

    return $panel;
  }

  private function buildRevisionPanel() {
    $user = $this->getRequest()->getUser();
    $user_phid = $user->getPHID();

    $revision_query = id(new DifferentialRevisionQuery())
      ->setViewer($user)
      ->withStatus(DifferentialRevisionQuery::STATUS_OPEN)
      ->withResponsibleUsers(array($user_phid))
      ->needRelationships(true)
      ->needFlags(true)
      ->needDrafts(true);

    $revisions = $revision_query->execute();

    list($blocking, $active, ) = DifferentialRevisionQuery::splitResponsible(
        $revisions,
        array($user_phid));

    if (!$blocking && !$active) {
      return $this->renderMiniPanel(
        'No Waiting Revisions',
        'No revisions are waiting on you.');
    }

    $title = pht('Revisions Waiting on You');
    $href = '/differential';
    $panel = new AphrontPanelView();
    $panel->setHeader($this->renderSectionHeader($title, $href));

    $revision_view = id(new DifferentialRevisionListView())
      ->setHighlightAge(true)
      ->setRevisions(array_merge($blocking, $active))
      ->setUser($user);
    $phids = array_merge(
      array($user_phid),
      $revision_view->getRequiredHandlePHIDs());
    $handles = $this->loadViewerHandles($phids);

    $revision_view->setHandles($handles);

    $list_view = $revision_view->render();
    $list_view->setFlush(true);

    $panel->appendChild($list_view);
    $panel->setNoBackground();

    return $panel;
  }

  private function buildWelcomePanel() {
    $panel = new AphrontPanelView();
    $panel->appendChild(
      phutil_safe_html(
        PhabricatorEnv::getEnvConfig('welcome.html')));
    $panel->setNoBackground();

    return $panel;
  }

  private function buildTasksPanel() {
    $user = $this->getRequest()->getUser();
    $user_phid = $user->getPHID();

    $task_query = id(new ManiphestTaskQuery())
      ->setViewer($user)
      ->withStatuses(ManiphestTaskStatus::getOpenStatusConstants())
      ->setGroupBy(ManiphestTaskQuery::GROUP_PRIORITY)
      ->withOwners(array($user_phid))
      ->setLimit(10);

    $tasks = $task_query->execute();


    if (!$tasks) {
      return $this->renderMiniPanel(
        'No Assigned Tasks',
        'You have no assigned tasks.');
    }

    $title = pht('Assigned Tasks');
    $href = '/maniphest';
    $panel = new AphrontPanelView();
    $panel->setHeader($this->renderSectionHeader($title, $href));
    $panel->appendChild($this->buildTaskListView($tasks));
    $panel->setNoBackground();

    return $panel;
  }

  private function buildTaskListView(array $tasks) {
    assert_instances_of($tasks, 'ManiphestTask');
    $user = $this->getRequest()->getUser();

    $phids = array_merge(
      array_filter(mpull($tasks, 'getOwnerPHID')),
      array_mergev(mpull($tasks, 'getProjectPHIDs')));

    $handles = $this->loadViewerHandles($phids);

    $view = new ManiphestTaskListView();
    $view->setTasks($tasks);
    $view->setUser($user);
    $view->setHandles($handles);

    return $view;
  }

  private function buildJumpPanel($query=null) {
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
    $doc_link = phutil_tag(
      'a',
      array(
        'href' => $doc_href,
      ),
      'Jump Nav User Guide');

    $jump_input = phutil_tag(
      'input',
      array(
        'type'  => 'text',
        'class' => 'phabricator-jump-nav',
        'name'  => 'jump',
        'id'    => $uniq_id,
        'value' => $query,
      ));
    $jump_caption = phutil_tag(
      'p',
      array(
        'class' => 'phabricator-jump-nav-caption',
      ),
      hsprintf(
        'Enter the name of an object like <tt>D123</tt> to quickly jump to '.
          'it. See %s or type <tt>help</tt>.',
        $doc_link));

    $form = phabricator_form(
      $user,
      array(
        'action' => '/jump/',
        'method' => 'POST',
        'class'  => 'phabricator-jump-nav-form',
      ),
      array(
        $jump_input,
        $jump_caption,
      ));

    $panel = new AphrontPanelView();
    $panel->setNoBackground();
    // $panel->appendChild();

    $list_filter = new AphrontListFilterView();
    $list_filter->appendChild($form);

    $container = phutil_tag('div',
      array('class' => 'phabricator-jump-nav-container'),
      $list_filter);

    return $container;
  }

  private function renderSectionHeader($title, $href) {
    $header = phutil_tag(
      'a',
      array(
        'href' => $href,
      ),
      $title);
    return $header;
  }

  private function renderMiniPanel($title, $body) {
    $panel = new AphrontMiniPanelView();
    $panel->appendChild(
      phutil_tag(
        'p',
        array(
        ),
        array(
          phutil_tag('strong', array(), $title.': '),
          $body
        )));
    $this->minipanels[] = $panel;
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
    $view->setUser($user);

    $phids = $view->getRequiredHandlePHIDs();
    $handles = $this->loadViewerHandles($phids);
    $view->setHandles($handles);

    $title = pht('Audits');
    $href = '/audit/';
    $panel = new AphrontPanelView();
    $panel->setHeader($this->renderSectionHeader($title, $href));
    $panel->appendChild($view);
    $panel->setNoBackground();

    return $panel;
  }

  public function buildCommitPanel() {
    $request = $this->getRequest();
    $user = $request->getUser();

    $phids = array($user->getPHID());

    $query = new PhabricatorAuditCommitQuery();
    $query->withAuthorPHIDs($phids);
    $query->withStatus(PhabricatorAuditCommitQuery::STATUS_CONCERN);
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
    $handles = $this->loadViewerHandles($phids);
    $view->setHandles($handles);

    $title = pht('Problem Commits');
    $href = '/audit/';
    $panel = new AphrontPanelView();
    $panel->setHeader($this->renderSectionHeader($title, $href));
    $panel->appendChild($view);
    $panel->setNoBackground();

    return $panel;
  }

}
