<?php

/**
 * Represents an abstract search engine for an application. It supports
 * creating and storing saved queries.
 *
 * @task builtin  Builtin Queries
 * @task uri      Query URIs
 * @task dates    Date Filters
 * @task read     Reading Utilities
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


  public function addNavigationItems(PHUIListView $menu) {
    $viewer = $this->requireViewer();

    $menu->newLabel(pht('Queries'));

    $named_queries = $this->loadEnabledNamedQueries();

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

  public function loadAllNamedQueries() {
    $viewer = $this->requireViewer();

    $named_queries = id(new PhabricatorNamedQueryQuery())
      ->setViewer($viewer)
      ->withUserPHIDs(array($viewer->getPHID()))
      ->withEngineClassNames(array(get_class($this)))
      ->execute();
    $named_queries = mpull($named_queries, null, 'getQueryKey');

    $builtin = $this->getBuiltinQueries($viewer);
    $builtin = mpull($builtin, null, 'getQueryKey');

    foreach ($named_queries as $key => $named_query) {
      if ($named_query->getIsBuiltin()) {
        if (isset($builtin[$key])) {
          $named_queries[$key]->setQueryName($builtin[$key]->getQueryName());
          unset($builtin[$key]);
        } else {
          unset($named_queries[$key]);
        }
      }

      unset($builtin[$key]);
    }

    $named_queries = msort($named_queries, 'getSortKey');

    return $named_queries + $builtin;
  }

  public function loadEnabledNamedQueries() {
    $named_queries = $this->loadAllNamedQueries();
    foreach ($named_queries as $key => $named_query) {
      if ($named_query->getIsBuiltin() && $named_query->getIsDisabled()) {
        unset($named_queries[$key]);
      }
    }
    return $named_queries;
  }


/* -(  Builtin Queries  )---------------------------------------------------- */


  /**
   * @task builtin
   */
  public function getBuiltinQueries() {
    $names = $this->getBuiltinQueryNames();

    $queries = array();
    $sequence = 0;
    foreach ($names as $key => $name) {
      $queries[$key] = id(new PhabricatorNamedQuery())
        ->setUserPHID($this->requireViewer()->getPHID())
        ->setEngineClassName(get_class($this))
        ->setQueryName($name)
        ->setQueryKey($key)
        ->setSequence((1 << 24) + $sequence++)
        ->setIsBuiltin(true);
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


/* -(  Reading Utilities )--------------------------------------------------- */


  /**
   * Read a list of user PHIDs from a request in a flexible way. This method
   * supports either of these forms:
   *
   *   users[]=alincoln&users[]=htaft
   *   users=alincoln,htaft
   *
   * Additionally, users can be specified either by PHID or by name.
   *
   * The main goal of this flexibility is to allow external programs to generate
   * links to pages (like "alincoln's open revisions") without needing to make
   * API calls.
   *
   * @param AphrontRequest  Request to read user PHIDs from.
   * @param string          Key to read in the request.
   * @return list<phid>     List of user PHIDs.
   *
   * @task read
   */
  protected function readUsersFromRequest(AphrontRequest $request, $key) {
    $list = $request->getArr($key, null);
    if ($list === null) {
      $list = $request->getStrList($key);
    }

    $phids = array();
    $names = array();
    $user_type = PhabricatorPHIDConstants::PHID_TYPE_USER;
    foreach ($list as $item) {
      if (phid_get_type($item) == $user_type) {
        $phids[] = $item;
      } else {
        $names[] = $item;
      }
    }

    if ($names) {
      $users = id(new PhabricatorPeopleQuery())
        ->setViewer($this->requireViewer())
        ->withUsernames($names)
        ->execute();
      foreach ($users as $user) {
        $phids[] = $user->getPHID();
      }
      $phids = array_unique($phids);
    }

    return $phids;
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


/* -(  Pagination  )--------------------------------------------------------- */


  public function getPageSize(PhabricatorSavedQuery $saved) {
    return $saved->getParameter('limit', 100);
  }


}
