<?php

final class PhabricatorApplicationReleeph extends PhabricatorApplication {

  public function getName() {
    return pht('Releeph');
  }

  public function getShortDescription() {
    return pht('Release Branches');
  }

  public function getBaseURI() {
    return '/releeph/';
  }

  public function getIconName() {
    return 'releeph';
  }

  public function getApplicationGroup() {
    return self::GROUP_ORGANIZATION;
  }

  public function isInstalled() {
    if (PhabricatorEnv::getEnvConfig('releeph.installed')) {
      return parent::isInstalled();
    }
    return false;
  }

  public function getRoutes() {
    return array(
      '/Y(?P<requestID>[1-9]\d*)' => 'ReleephRequestViewController',

      // TODO: Remove these older routes eventually.
      '/RQ(?P<requestID>[1-9]\d*)' => 'ReleephRequestViewController',
      '/releeph/request/(?P<requestID>[1-9]\d*)/'
        => 'ReleephRequestViewController',

      '/releeph/' => array(
        '' => 'ReleephProductListController',
        'project/' => array(
          '(?:query/(?P<queryKey>[^/]+)/)?' => 'ReleephProductListController',
          'create/' => 'ReleephProductCreateController',
          '(?P<projectID>[1-9]\d*)/' => array(
            '(?:query/(?P<queryKey>[^/]+)/)?' => 'ReleephProductViewController',
            'edit/' => 'ReleephProductEditController',
            'cutbranch/' => 'ReleephBranchCreateController',
            'action/(?P<action>.+)/' => 'ReleephProductActionController',
            'history/' => 'ReleephProductHistoryController',
          ),
        ),

        'branch/' => array(
          'edit/(?P<branchID>[1-9]\d*)/' =>
            'ReleephBranchEditController',
          '(?P<action>close|re-open)/(?P<branchID>[1-9]\d*)/' =>
            'ReleephBranchAccessController',
          'preview/' => 'ReleephBranchNamePreviewController',
          '(?P<branchID>[1-9]\d*)/' => array(
            'history/' => 'ReleephBranchHistoryController',
            '(?:query/(?P<queryKey>[^/]+)/)?' => 'ReleephBranchViewController',
          ),
          'pull/(?P<branchID>[1-9]\d*)/' =>
            'ReleephRequestEditController',
        ),

        'request/' => array(
          'create/' => 'ReleephRequestEditController',
          'differentialcreate/' => array(
            'D(?P<diffRevID>[1-9]\d*)' =>
              'ReleephRequestDifferentialCreateController',
          ),
          'edit/(?P<requestID>[1-9]\d*)/' =>
            'ReleephRequestEditController',
          'action/(?P<action>.+)/(?P<requestID>[1-9]\d*)/' =>
            'ReleephRequestActionController',
          'typeahead/' =>
            'ReleephRequestTypeaheadController',
          'comment/(?P<requestID>[1-9]\d*)/' =>
            'ReleephRequestCommentController',
        ),
      )
    );
  }

}
