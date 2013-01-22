<?php

final class PhabricatorApplicationManiphest extends PhabricatorApplication {

  public function getShortDescription() {
    return 'Tasks and Bugs';
  }

  public function getBaseURI() {
    return '/maniphest/';
  }

  public function isEnabled() {
    return PhabricatorEnv::getEnvConfig('maniphest.enabled');
  }

  public function getIconName() {
    return 'maniphest';
  }

  public function getApplicationGroup() {
    return self::GROUP_CORE;
  }

  public function getApplicationOrder() {
    return 0.110;
  }

  public function getFactObjectsForAnalysis() {
    return array(
      new ManiphestTask(),
    );
  }

  public function getQuickCreateURI() {
    return $this->getBaseURI().'task/create/';
  }

  public function getRoutes() {
    return array(
      '/T(?P<id>[1-9]\d*)' => 'ManiphestTaskDetailController',
      '/maniphest/' => array(
        '' => 'ManiphestTaskListController',
        'view/(?P<view>\w+)/' => 'ManiphestTaskListController',
        'report/(?:(?P<view>\w+)/)?' => 'ManiphestReportController',
        'batch/' => 'ManiphestBatchEditController',
        'task/' => array(
          'create/' => 'ManiphestTaskEditController',
          'edit/(?P<id>[1-9]\d*)/' => 'ManiphestTaskEditController',
          'descriptionchange/(?:(?P<id>[1-9]\d*)/)?' =>
            'ManiphestTaskDescriptionChangeController',
          'descriptionpreview/' =>
            'ManiphestTaskDescriptionPreviewController',
        ),
        'transaction/' => array(
          'save/' => 'ManiphestTransactionSaveController',
          'preview/(?P<id>[1-9]\d*)/'
            => 'ManiphestTransactionPreviewController',
        ),
        'export/(?P<key>[^/]+)/' => 'ManiphestExportController',
        'subpriority/' => 'ManiphestSubpriorityController',
        'custom/' => array(
          '' => 'ManiphestSavedQueryListController',
          'edit/(?:(?P<id>[1-9]\d*)/)?' => 'ManiphestSavedQueryEditController',
          'delete/(?P<id>[1-9]\d*)/'   => 'ManiphestSavedQueryDeleteController',
        ),
      ),
    );
  }

  public function loadStatus(PhabricatorUser $user) {
    $status = array();

    $query = id(new ManiphestTaskQuery())
      ->withStatus(ManiphestTaskQuery::STATUS_OPEN)
      ->withPriority(ManiphestTaskPriority::PRIORITY_UNBREAK_NOW)
      ->setLimit(1)
      ->setCalculateRows(true);
    $query->execute();

    $count = $query->getRowCount();
    $type = $count
      ? PhabricatorApplicationStatusView::TYPE_NEEDS_ATTENTION
      : PhabricatorApplicationStatusView::TYPE_EMPTY;
    $status[] = id(new PhabricatorApplicationStatusView())
      ->setType($type)
      ->setText(pht('%d Unbreak Now Task(s)!', $count))
      ->setCount($count);

    $query = id(new ManiphestTaskQuery())
      ->withStatus(ManiphestTaskQuery::STATUS_OPEN)
      ->withOwners(array($user->getPHID()))
      ->setLimit(1)
      ->setCalculateRows(true);
    $query->execute();

    $count = $query->getRowCount();
    $type = $count
      ? PhabricatorApplicationStatusView::TYPE_INFO
      : PhabricatorApplicationStatusView::TYPE_EMPTY;
    $status[] = id(new PhabricatorApplicationStatusView())
      ->setType($type)
      ->setText(pht('%d Assigned Task(s)', $count));

    return $status;
  }

}

