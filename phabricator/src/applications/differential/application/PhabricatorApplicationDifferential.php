<?php

final class PhabricatorApplicationDifferential extends PhabricatorApplication {

  public function getBaseURI() {
    return '/differential/';
  }

  public function getShortDescription() {
    return pht('Review Code');
  }

  public function getIconName() {
    return 'differential';
  }

  public function getHelpURI() {
    return PhabricatorEnv::getDoclink('article/Differential_User_Guide.html');
  }

  public function getFactObjectsForAnalysis() {
    return array(
      new DifferentialRevision(),
    );
  }

  public function getTitleGlyph() {
    return "\xE2\x9A\x99";
  }

  public function getEventListeners() {
    return array(
      new DifferentialPeopleMenuEventListener()
    );
  }

  public function getRoutes() {
    return array(
      '/D(?P<id>[1-9]\d*)' => 'DifferentialRevisionViewController',
      '/differential/' => array(
        '' => 'DifferentialRevisionListController',
        'filter/(?P<filter>\w+)/(?:(?P<username>[\w\.-_]+)/)?' =>
          'DifferentialRevisionListController',
        'stats/(?P<filter>\w+)/' => 'DifferentialRevisionStatsController',
        'diff/' => array(
          '(?P<id>[1-9]\d*)/' => 'DifferentialDiffViewController',
          'create/' => 'DifferentialDiffCreateController',
        ),
        'changeset/' => 'DifferentialChangesetViewController',
        'revision/edit/(?:(?P<id>[1-9]\d*)/)?'
          => 'DifferentialRevisionEditController',
        'comment/' => array(
          'preview/(?P<id>[1-9]\d*)/' => 'DifferentialCommentPreviewController',
          'save/' => 'DifferentialCommentSaveController',
          'inline/' => array(
            'preview/(?P<id>[1-9]\d*)/'
              => 'DifferentialInlineCommentPreviewController',
            'edit/(?P<id>[1-9]\d*)/'
              => 'DifferentialInlineCommentEditController',
          ),
        ),
        'subscribe/(?P<action>add|rem)/(?P<id>[1-9]\d*)/'
          => 'DifferentialSubscribeController',
      ),
    );
  }

  public function getApplicationGroup() {
    return self::GROUP_CORE;
  }

  public function getApplicationOrder() {
    return 0.100;
  }

  public function loadStatus(PhabricatorUser $user) {
    $revisions = id(new DifferentialRevisionQuery())
      ->withResponsibleUsers(array($user->getPHID()))
      ->withStatus(DifferentialRevisionQuery::STATUS_OPEN)
      ->execute();

    list($blocking, $active, $waiting) =
      DifferentialRevisionQuery::splitResponsible(
        $revisions,
        array($user->getPHID()));

    $status = array();

    $active = count($blocking) + count($active);
    $type = $active
      ? PhabricatorApplicationStatusView::TYPE_NEEDS_ATTENTION
      : PhabricatorApplicationStatusView::TYPE_EMPTY;
    $status[] = id(new PhabricatorApplicationStatusView())
      ->setType($type)
      ->setText(pht('%d Review(s) Need Attention', $active))
      ->setCount($active);

    $waiting = count($waiting);
    $type = $waiting
      ? PhabricatorApplicationStatusView::TYPE_INFO
      : PhabricatorApplicationStatusView::TYPE_EMPTY;
    $status[] = id(new PhabricatorApplicationStatusView())
      ->setType($type)
      ->setText(pht('%d Review(s) Waiting on Others', $waiting));

    return $status;
  }

}

