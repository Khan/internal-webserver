<?php

/**
 * Represents an abstract search engine for an application. It supports
 * creating and storing saved queries.
 *
 * @task builtin  Builtin Queries
 * @task uri      Query URIs
 * @task dates    Date Filters
 *
 * @group search
 */
abstract class PhabricatorApplicationSearchEngine {

  private $viewer;
  private $errors = array();

  public function setViewer(PhabricatorUser $viewer) {
    $this->viewer = $viewer;
    return $this;
  }

  protected function requireViewer() {
    if (!$this->viewer) {
      throw new Exception("Call setViewer() before using an engine!");
    }
    return $this->viewer;
  }

  /**
   * Create a saved query object from the request.
   *
   * @param AphrontRequest The search request.
   * @return PhabricatorSavedQuery
   */
  abstract public function buildSavedQueryFromRequest(
    AphrontRequest $request);

  /**
   * Executes the saved query.
   *
   * @param PhabricatorSavedQuery The saved query to operate on.
   * @return The result of the query.
   */
  abstract public function buildQueryFromSavedQuery(
    PhabricatorSavedQuery $saved);

  /**
   * Builds the search form using the request.
   *
   * @param AphrontFormView       Form to populate.
   * @param PhabricatorSavedQuery The query from which to build the form.
   * @return void
   */
  abstract public function buildSearchForm(
    AphrontFormView $form,
    PhabricatorSavedQuery $query);

  public function getErrors() {
    return $this->errors;
  }

  public function addError($error) {
    $this->errors[] = $error;
    return $this;
  }

  /**
   * Return an application URI corresponding to the results page of a query.
   * Normally, this is something like `/application/query/QUERYKEY/`.
   *
   * @param   string  The query key to build a URI for.
   * @return  string  URI where the query can be executed.
   * @task uri
   */
  public function getQueryResultsPageURI($query_key) {
    return $this->getURI('query/'.$query_key.'/');
  }


  /**
   * Return an application URI for query management. This is used when, e.g.,
   * a query deletion operation is cancelled.
   *
   * @return  string  URI where queries can be managed.
   * @task uri
   */
  public function getQueryManagementURI() {
    return $this->getURI('query/edit/');
  }


  /**
   * Return the URI to a path within the application. Used to construct default
   * URIs for management and results.
   *
   * @return string URI to path.
   * @task uri
   */
  abstract protected function getURI($path);


  public function newSavedQuery() {
    return id(new PhabricatorSavedQuery())
      ->setEngineClassName(get_class($this));
  }


  public function addNavigationItems(PhabricatorMenuView $menu) {
    $viewer = $this->requireViewer();

    $menu->newLabel(pht('Queries'));

    $named_queries = id(new PhabricatorNamedQueryQuery())
      ->setViewer($viewer)
      ->withUserPHIDs(array($viewer->getPHID()))
      ->withEngineClassNames(array(get_class($this)))
      ->execute();

    $named_queries = $named_queries + $this->getBuiltinQueries($viewer);

    foreach ($named_queries as $query) {
      $key = $query->getQueryKey();
      $uri = $this->getQueryResultsPageURI($key);
      $menu->newLink($query->getQueryName(), $uri, 'query/'.$key);
    }

    if ($viewer->isLoggedIn()) {
      $manage_uri = $this->getQueryManagementURI();
      $menu->newLink(pht('Edit Queries...'), $manage_uri, 'query/edit');
    }

    $menu->newLabel(pht('Search'));
    $advanced_uri = $this->getQueryResultsPageURI('advanced');
    $menu->newLink(pht('Advanced Search'), $advanced_uri, 'query/advanced');

    return $this;
  }


/* -(  Builtin Queries  )---------------------------------------------------- */


  /**
   * @task builtin
   */
  public function getBuiltinQueries() {
    $names = $this->getBuiltinQueryNames();

    $queries = array();
    foreach ($names as $key => $name) {
      $queries[$key] = id(new PhabricatorNamedQuery())
        ->setQueryName($name)
        ->setQueryKey($key)
        ->setIsBuiltin(true)
        ->makeEphemeral();
    }

    return $queries;
  }


  /**
   * @task builtin
   */
  public function getBuiltinQuery($query_key) {
    if (!$this->isBuiltinQuery($query_key)) {
      throw new Exception("'{$query_key}' is not a builtin!");
    }
    return idx($this->getBuiltinQueries(), $query_key);
  }


  /**
   * @task builtin
   */
  protected function getBuiltinQueryNames() {
    return array();
  }


  /**
   * @task builtin
   */
  public function isBuiltinQuery($query_key) {
    $builtins = $this->getBuiltinQueries();
    return isset($builtins[$query_key]);
  }


  /**
   * @task builtin
   */
  public function buildSavedQueryFromBuiltin($query_key) {
    throw new Exception("Builtin '{$query_key}' is not supported!");
  }


/* -(  Dates  )-------------------------------------------------------------- */


  /**
   * @task dates
   */
  protected function parseDateTime($date_time) {
    if (!strlen($date_time)) {
      return null;
    }

    return PhabricatorTime::parseLocalTime($date_time, $this->requireViewer());
  }


  /**
   * @task dates
   */
  protected function buildDateRange(
    AphrontFormView $form,
    PhabricatorSavedQuery $saved_query,
    $start_key,
    $start_name,
    $end_key,
    $end_name) {

    $start_str = $saved_query->getParameter($start_key);
    $start = null;
    if (strlen($start_str)) {
      $start = $this->parseDateTime($start_str);
      if (!$start) {
        $this->addError(
          pht(
            '"%s" date can not be parsed.',
            $start_name));
      }
    }


    $end_str = $saved_query->getParameter($end_key);
    $end = null;
    if (strlen($end_str)) {
      $end = $this->parseDateTime($end_str);
      if (!$end) {
        $this->addError(
          pht(
            '"%s" date can not be parsed.',
            $end_name));
      }
    }

    if ($start && $end && ($start >= $end)) {
      $this->addError(
        pht(
          '"%s" must be a date before "%s".',
          $start_name,
          $end_name));
    }

    $form
      ->appendChild(
        id(new PHUIFormFreeformDateControl())
          ->setName($start_key)
          ->setLabel($start_name)
          ->setValue($start_str))
      ->appendChild(
        id(new AphrontFormTextControl())
          ->setName($end_key)
          ->setLabel($end_name)
          ->setValue($end_str));
  }
}
