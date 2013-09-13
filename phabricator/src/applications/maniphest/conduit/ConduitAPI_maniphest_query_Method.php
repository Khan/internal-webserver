<?php

/**
 * @group conduit
 *
 * TODO: Remove maniphest.find, then make this final.
 *
 * @concrete-extensible
 */
class ConduitAPI_maniphest_query_Method
  extends ConduitAPI_maniphest_Method {


  public function getMethodDescription() {
    return "Execute complex searches for Maniphest tasks.";
  }

  public function defineParamTypes() {

    $statuses = array(
      ManiphestTaskQuery::STATUS_ANY,
      ManiphestTaskQuery::STATUS_OPEN,
      ManiphestTaskQuery::STATUS_CLOSED,
      ManiphestTaskQuery::STATUS_RESOLVED,
      ManiphestTaskQuery::STATUS_WONTFIX,
      ManiphestTaskQuery::STATUS_INVALID,
      ManiphestTaskQuery::STATUS_SPITE,
      ManiphestTaskQuery::STATUS_DUPLICATE,
    );
    $statuses = implode(', ', $statuses);

    $orders = array(
      ManiphestTaskQuery::ORDER_PRIORITY,
      ManiphestTaskQuery::ORDER_CREATED,
      ManiphestTaskQuery::ORDER_MODIFIED,
    );
    $orders = implode(', ', $orders);

    return array(
      'ids'               => 'optional list<uint>',
      'phids'             => 'optional list<phid>',
      'ownerPHIDs'        => 'optional list<phid>',
      'authorPHIDs'       => 'optional list<phid>',
      'projectPHIDs'      => 'optional list<phid>',
      'ccPHIDs'           => 'optional list<phid>',
      'fullText'          => 'optional string',

      'status'            => 'optional enum<'.$statuses.'>',
      'order'             => 'optional enum<'.$orders.'>',

      'limit'             => 'optional int',
      'offset'            => 'optional int',
    );
  }

  public function defineReturnType() {
    return 'list';
  }

  public function defineErrorTypes() {
    return array(
    );
  }

  protected function execute(ConduitAPIRequest $request) {
    $query = new ManiphestTaskQuery();

    $query->setViewer($request->getUser());

    $task_ids = $request->getValue('ids');
    if ($task_ids) {
      $query->withIDs($task_ids);
    }

    $task_phids = $request->getValue('phids');
    if ($task_phids) {
      $query->withPHIDs($task_phids);
    }

    $owners = $request->getValue('ownerPHIDs');
    if ($owners) {
      $query->withOwners($owners);
    }

    $authors = $request->getValue('authorPHIDs');
    if ($authors) {
      $query->withAuthors($authors);
    }

    $projects = $request->getValue('projectPHIDs');
    if ($projects) {
      $query->withAllProjects($projects);
    }

    $ccs = $request->getValue('ccPHIDs');
    if ($ccs) {
      $query->withSubscribers($ccs);
    }

    $full_text = $request->getValue('fullText');
    if ($full_text) {
      $query->withFullTextSearch($full_text);
    }

    $status = $request->getValue('status');
    if ($status) {
      $query->withStatus($status);
    }

    $order = $request->getValue('order');
    if ($order) {
      $query->setOrderBy($order);
    }

    $limit = $request->getValue('limit');
    if ($limit) {
      $query->setLimit($limit);
    }

    $offset = $request->getValue('offset');
    if ($offset) {
      $query->setOffset($offset);
    }

    $results = $query->execute();
    return $this->buildTaskInfoDictionaries($results);
  }

}
