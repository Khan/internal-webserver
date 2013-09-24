<?php

/**
 * @group conduit
 */
final class ConduitAPI_differential_getdiff_Method
  extends ConduitAPIMethod {

  public function getMethodStatus() {
    return self::METHOD_STATUS_DEPRECATED;
  }

  public function getMethodStatusDescription() {
    return pht(
      'This method has been deprecated in favor of differential.querydiffs.');
  }


  public function getMethodDescription() {
    return pht('Load the content of a diff from Differential by revision id '.
               'or diff id.');
  }

  public function defineParamTypes() {
    return array(
      'revision_id' => 'optional id',
      'diff_id'     => 'optional id',
    );
  }

  public function defineReturnType() {
    return 'nonempty dict';
  }

  public function defineErrorTypes() {
    return array(
      'ERR_BAD_DIFF'        => 'No such diff exists.',
    );
  }

  public function shouldRequireAuthentication() {
    return !PhabricatorEnv::getEnvConfig('differential.anonymous-access');
  }

  protected function execute(ConduitAPIRequest $request) {
    $diff_id = $request->getValue('diff_id');

    // If we have a revision ID, we need the most recent diff. Figure that out
    // without loading all the attached data.
    $revision_id = $request->getValue('revision_id');
    if ($revision_id) {
      $diffs = id(new DifferentialDiffQuery())
        ->setViewer($request->getUser())
        ->withRevisionIDs(array($revision_id))
        ->execute();
      if ($diffs) {
        $diff_id = head($diffs)->getID();
      } else {
        throw new ConduitException('ERR_BAD_DIFF');
      }
    }

    $diff = null;
    if ($diff_id) {
      $diff = id(new DifferentialDiffQuery())
        ->setViewer($request->getUser())
        ->withIDs(array($diff_id))
        ->needChangesets(true)
        ->needArcanistProjects(true)
        ->executeOne();
    }

    if (!$diff) {
      throw new ConduitException('ERR_BAD_DIFF');
    }

    return $diff->getDiffDict();
  }

}
