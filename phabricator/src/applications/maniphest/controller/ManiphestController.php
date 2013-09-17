<?php

abstract class ManiphestController extends PhabricatorController {

  public function buildApplicationMenu() {
    return $this->buildSideNavView(true)->getMenu();
  }

  public function buildSideNavView($for_app = false) {
    $user = $this->getRequest()->getUser();

    $nav = new AphrontSideNavFilterView();
    $nav->setBaseURI(new PhutilURI($this->getApplicationURI()));

    if ($for_app) {
      $nav->addFilter('create', pht('Create Task'));
    }

    id(new ManiphestTaskSearchEngine())
      ->setViewer($user)
      ->addNavigationItems($nav->getMenu());

    $nav->addLabel(pht('Reports'));
    $nav->addFilter('report', pht('Reports'));

    $nav->selectFilter(null);

    return $nav;
  }

  protected function buildApplicationCrumbs() {
    $crumbs = parent::buildApplicationCrumbs();

    $crumbs->addAction(
      id(new PHUIListItemView())
        ->setName(pht('Create Task'))
        ->setHref($this->getApplicationURI('task/create/'))
        ->setIcon('create'));

    return $crumbs;
  }

  protected function renderSingleTask(ManiphestTask $task) {
    $user = $this->getRequest()->getUser();

    $phids = $task->getProjectPHIDs();
    if ($task->getOwnerPHID()) {
      $phids[] = $task->getOwnerPHID();
    }

    $handles = id(new PhabricatorHandleQuery())
      ->setViewer($user)
      ->withPHIDs($phids)
      ->execute();

    $view = id(new ManiphestTaskListView())
      ->setUser($user)
      ->setShowSubpriorityControls(true)
      ->setShowBatchControls(true)
      ->setHandles($handles)
      ->setTasks(array($task));

    return $view;
  }


}
