<?php

final class ReleephRequestSearchEngine
  extends PhabricatorApplicationSearchEngine {

  private $branch;
  private $baseURI;

  public function setBranch(ReleephBranch $branch) {
    $this->branch = $branch;
    return $this;
  }

  public function getBranch() {
    return $this->branch;
  }

  public function setBaseURI($base_uri) {
    $this->baseURI = $base_uri;
    return $this;
  }

  public function buildSavedQueryFromRequest(AphrontRequest $request) {
    $saved = new PhabricatorSavedQuery();

    $saved->setParameter('status', $request->getStr('status'));
    $saved->setParameter('severity', $request->getStr('severity'));
    $saved->setParameter('requestorPHIDs', $request->getArr('requestorPHIDs'));

    return $saved;
  }

  public function buildQueryFromSavedQuery(PhabricatorSavedQuery $saved) {
    $query = id(new ReleephRequestQuery())
      ->withBranchIDs(array($this->getBranch()->getID()));

    $status = $saved->getParameter('status');
    $status = idx($this->getStatusValues(), $status);
    if ($status) {
      $query->withStatus($status);
    }

    $severity = $saved->getParameter('severity');
    if ($severity) {
      $query->withSeverities(array($severity));
    }

    $requestor_phids = $saved->getParameter('requestorPHIDs');
    if ($requestor_phids) {
      $query->withRequestorPHIDs($requestor_phids);
    }

    return $query;
  }

  public function buildSearchForm(
    AphrontFormView $form,
    PhabricatorSavedQuery $saved_query) {

    $phids = $saved_query->getParameter('requestorPHIDs', array());
    $handles = id(new PhabricatorObjectHandleData($phids))
      ->setViewer($this->requireViewer())
      ->loadHandles();
    $requestor_tokens = mpull($handles, 'getFullName', 'getPHID');

    $form
      ->appendChild(
        id(new AphrontFormSelectControl())
          ->setName('status')
          ->setLabel(pht('Status'))
          ->setValue($saved_query->getParameter('status'))
          ->setOptions($this->getStatusOptions()))
      ->appendChild(
        id(new AphrontFormSelectControl())
          ->setName('severity')
          ->setLabel(pht('Severity'))
          ->setValue($saved_query->getParameter('severity'))
          ->setOptions($this->getSeverityOptions()))
      ->appendChild(
        id(new AphrontFormTokenizerControl())
          ->setDatasource('/typeahead/common/users/')
          ->setName('requestorPHIDs')
          ->setLabel(pht('Requestors'))
          ->setValue($requestor_tokens));
  }

  protected function getURI($path) {
    return $this->baseURI.$path;
  }

  public function getBuiltinQueryNames() {
    $names = array(
      'open' => pht('Open Requests'),
      'all' => pht('All Requests'),
    );

    if ($this->requireViewer()->isLoggedIn()) {
      $names['requested'] = pht('Requested');
    }

    return $names;
  }

  public function buildSavedQueryFromBuiltin($query_key) {

    $query = $this->newSavedQuery();
    $query->setQueryKey($query_key);

    switch ($query_key) {
      case 'open':
        return $query->setParameter('status', 'open');
      case 'all':
        return $query;
      case 'requested':
        return $query->setParameter(
          'requestorPHIDs',
          array($this->requireViewer()->getPHID()));
    }

    return parent::buildSavedQueryFromBuiltin($query_key);
  }

  private function getStatusOptions() {
    return array(
      ''              => pht('(All Requests)'),
      'open'          => pht('Open Requests'),
      'requested'     => pht('Pull Requested'),
      'needs-pull'    => pht('Needs Pull'),
      'rejected'      => pht('Rejected'),
      'abandoned'     => pht('Abandoned'),
      'pulled'        => pht('Pulled'),
      'needs-revert'  => pht('Needs Revert'),
      'reverted'      => pht('Reverted'),
    );
  }

  private function getStatusValues() {
    return array(
      'open'          => ReleephRequestQuery::STATUS_OPEN,
      'requested'     => ReleephRequestQuery::STATUS_REQUESTED,
      'needs-pull'    => ReleephRequestQuery::STATUS_NEEDS_PULL,
      'rejected'      => ReleephRequestQuery::STATUS_REJECTED,
      'abandoned'     => ReleephRequestQuery::STATUS_ABANDONED,
      'pulled'        => ReleephRequestQuery::STATUS_PULLED,
      'needs-revert'  => ReleephRequestQuery::STATUS_NEEDS_REVERT,
      'reverted'      => ReleephRequestQuery::STATUS_REVERTED,
    );
  }

  private function getSeverityOptions() {
    return array(
      '' => pht('(All Severities)'),
      ReleephSeverityFieldSpecification::HOTFIX => pht('Hotfix'),
      ReleephSeverityFieldSpecification::RELEASE => pht('Release'),
    );
  }

}
