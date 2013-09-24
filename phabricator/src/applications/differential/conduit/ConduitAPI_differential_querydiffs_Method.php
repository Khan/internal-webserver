<?php

/**
 * @group conduit
 */
final class ConduitAPI_differential_querydiffs_Method
  extends ConduitAPIMethod {

  public function getMethodDescription() {
    return pht('Query differential diffs which match certain criteria.');
  }

  public function defineParamTypes() {
    return array(
      'ids' => 'optional list<uint>',
      'revisonIDs' => 'optional list<uint>',
    );
  }

  public function defineErrorTypes() {
    return array();
  }

  public function defineReturnType() {
    return 'list<dict>';
  }

  protected function execute(ConduitAPIRequest $request) {
    $ids = $request->getValue('ids', array());
    $revision_ids = $request->getValue('revisionIDs', array());
    $diffs = array();
    $diff_dicts = array();
    if ($ids || $revision_ids) {
      $diffs = id(new DifferentialDiffQuery())
        ->setViewer($request->getUser())
        ->withIDs($ids)
        ->withRevisionIDs($revision_ids)
        ->needChangesets(true)
        ->needArcanistProjects(true)
        ->execute();
    }

    return mpull($diffs, 'getDiffDict', 'getID');
  }
}
