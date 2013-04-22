<?php

/**
 * Provides search functionality for the paste application.
 *
 * @group search
 */
final class PhabricatorPasteSearchEngine
  extends PhabricatorApplicationSearchEngine {

  protected $filter;

  /**
   * Create a saved query object from the request.
   *
   * @param AphrontRequest The search request.
   * @return The saved query that is built.
   */
  public function buildSavedQueryFromRequest(AphrontRequest $request) {

    $saved = new PhabricatorSavedQuery();

    if ($this->filter == "my") {
      $user = $request->getUser();
      $saved->setParameter('authorPHIDs', array($user->getPHID()));
    }

    return $saved;
  }

  /**
   * Executes the saved query.
   *
   * @param PhabricatorSavedQuery
   * @return The result of the query.
   */
  public function buildQueryFromSavedQuery(PhabricatorSavedQuery $saved) {
    $query = id(new PhabricatorPasteQuery())
      ->withIDs($saved->getParameter('ids', array()))
      ->withPHIDs($saved->getParameter('phids', array()))
      ->withAuthorPHIDs($saved->getParameter('authorPHIDs', array()))
      ->withParentPHIDs($saved->getParameter('parentPHIDs', array()));

    return $query;
  }

  /**
   * Builds the search form using the request.
   *
   * @param PhabricatorSavedQuery The query to populate the form with.
   * @return void
   */
  public function buildSearchForm(PhabricatorSavedQuery $saved_query) {
  }

  public function setPasteSearchFilter($filter) {
    $this->filter = $filter;
    return $this;
  }

  public function getPasteSearchFilter() {
    return $this->filter;
  }

}
