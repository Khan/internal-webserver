<?php

final class PhabricatorFeedSearchEngine
  extends PhabricatorApplicationSearchEngine {

  public function buildSavedQueryFromRequest(AphrontRequest $request) {
    $saved = new PhabricatorSavedQuery();

    $saved->setParameter(
      'userPHIDs',
      array_values($request->getArr('userPHIDs')));

    $saved->setParameter(
      'projectPHIDs',
      array_values($request->getArr('projectPHIDs')));

    $saved->setParameter(
      'viewerProjects',
      $request->getBool('viewerProjects'));

    return $saved;
  }

  public function buildQueryFromSavedQuery(PhabricatorSavedQuery $saved) {
    $query = id(new PhabricatorFeedQuery());

    $phids = array();

    $user_phids = $saved->getParameter('userPHIDs');
    if ($user_phids) {
      $phids[] = $user_phids;
    }

    $proj_phids = $saved->getParameter('projectPHIDs');
    if ($proj_phids) {
      $phids[] = $proj_phids;
    }

    $viewer_projects = $saved->getParameter('viewerProjects');
    if ($viewer_projects) {
      $viewer = $this->requireViewer();
      $projects = id(new PhabricatorProjectQuery())
        ->setViewer($viewer)
        ->withMemberPHIDs(array($viewer->getPHID()))
        ->execute();
      $phids[] = mpull($projects, 'getPHID');
    }

    $phids = array_mergev($phids);
    if ($phids) {
      $query->setFilterPHIDs($phids);
    }

    return $query;
  }

  public function buildSearchForm(
    AphrontFormView $form,
    PhabricatorSavedQuery $saved_query) {

    $user_phids = $saved_query->getParameter('userPHIDs', array());
    $proj_phids = $saved_query->getParameter('projectPHIDs', array());

    $phids = array_merge($user_phids, $proj_phids);
    $handles = id(new PhabricatorHandleQuery())
      ->setViewer($this->requireViewer())
      ->withPHIDs($phids)
      ->execute();
    $tokens = mpull($handles, 'getFullName', 'getPHID');
    $user_tokens = array_select_keys($tokens, $user_phids);
    $proj_tokens = array_select_keys($tokens, $proj_phids);

    $viewer_projects = $saved_query->getParameter('viewerProjects');

    $form
      ->appendChild(
        id(new AphrontFormTokenizerControl())
          ->setDatasource('/typeahead/common/users/')
          ->setName('userPHIDs')
          ->setLabel(pht('Include Users'))
          ->setValue($user_tokens))
      ->appendChild(
        id(new AphrontFormTokenizerControl())
          ->setDatasource('/typeahead/common/projects/')
          ->setName('projectPHIDs')
          ->setLabel(pht('Include Projects'))
          ->setValue($proj_tokens))
      ->appendChild(
        id(new AphrontFormCheckboxControl())
          ->addCheckbox(
            'viewerProjects',
            1,
            pht('Include stories about projects I am a member of.'),
            $viewer_projects));
  }

  protected function getURI($path) {
    return '/feed/'.$path;
  }

  public function getBuiltinQueryNames() {
    $names = array(
      'all' => pht('All Stories'),
    );

    if ($this->requireViewer()->isLoggedIn()) {
      $names['projects'] = pht('Projects');
    }

    return $names;
  }

  public function buildSavedQueryFromBuiltin($query_key) {

    $query = $this->newSavedQuery();
    $query->setQueryKey($query_key);

    switch ($query_key) {
      case 'all':
        return $query;
      case 'projects':
        return $query->setParameter('viewerProjects', true);
    }

    return parent::buildSavedQueryFromBuiltin($query_key);
  }

}
