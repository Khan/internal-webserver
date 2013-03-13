<?php

/**
 * @group conduit
 */
final class ConduitAPI_owners_query_Method
  extends ConduitAPI_owners_Method {

  public function getMethodDescription() {
    return 'Query for packages by one of the following: repository/path, ' .
      'packages with a given user or project owner, or packages affiliated ' .
      'with a user (owned by either the user or a project they are a member ' .
      'of.) You should only provide at most one search query.';
  }

  public function defineParamTypes() {
    return array(
      'userOwner'                  => 'optional string',
      'projectOwner'               => 'optional string',
      'userAffiliated'             => 'optional string',
      'repositoryCallsign'         => 'optional string',
      'path'                       => 'optional string',
    );

  }

  public function defineReturnType() {
    return 'dict<phid -> dict of package info>';
  }

  public function defineErrorTypes() {
    return array(
      'ERR-INVALID-USAGE' =>
        'Provide one of a single owner phid (user/project), a single ' .
        'affiliated user phid (user), or a repository/path.',
      'ERR-INVALID-PARAMETER' => 'parameter should be a phid',
      'ERR_REP_NOT_FOUND'  => 'The repository callsign is not recognized',
    );
  }

  protected static function queryAll() {
    return id(new PhabricatorOwnersPackage())->loadAll();
  }

  protected static function queryByOwner($owner) {
    $is_valid_phid =
      phid_get_type($owner) == PhabricatorPHIDConstants::PHID_TYPE_USER ||
      phid_get_type($owner) == PhabricatorPHIDConstants::PHID_TYPE_PROJ;

    if (!$is_valid_phid) {
      throw id(new ConduitException('ERR-INVALID-PARAMETER'))
        ->setErrorDescription(
          'Expected user/project PHID for owner, got '.$owner);
    }

    $owners = id(new PhabricatorOwnersOwner())->loadAllWhere(
      'userPHID = %s',
      $owner);

    $package_ids = mpull($owners, 'getPackageID');
    $packages = array();
    foreach ($package_ids as $id) {
      $packages[] = id(new PhabricatorOwnersPackage())->load($id);
    }
    return $packages;
  }

  private static function queryByPath($repo_callsign, $path) {
    $repository = id(new PhabricatorRepository())->loadOneWhere('callsign = %s',
      $repo_callsign);

    if (empty($repository)) {
      throw id(new ConduitException('ERR_REP_NOT_FOUND'))
        ->setErrorDescription(
          'Repository callsign '.$repo_callsign.' not recognized');
    }

    return PhabricatorOwnersPackage::loadOwningPackages(
      $repository, $path);
  }

  public static function buildPackageInformationDictionaries($packages) {
    assert_instances_of($packages, 'PhabricatorOwnersPackage');

    $result = array();
    foreach ($packages as $package) {
      $p_owners = $package->loadOwners();
      $p_paths = $package->loadPaths();

      $owners = array_values(mpull($p_owners, 'getUserPHID'));
      $paths = array();
      foreach ($p_paths as $p) {
        $paths[] = array($p->getRepositoryPHID(), $p->getPath());
      }

      $result[$package->getPHID()] = array(
        'phid' => $package->getPHID(),
        'name' => $package->getName(),
        'description' => $package->getDescription(),
        'primaryOwner' => $package->getPrimaryOwnerPHID(),
        'owners' => $owners,
        'paths' => $paths
      );
    }
    return $result;
  }

  protected function execute(ConduitAPIRequest $request) {
    $is_owner_query =
      ($request->getValue('userOwner') ||
       $request->getValue('projectOwner')) ?
      1 : 0;

    $is_affiliated_query = $request->getValue('userAffiliated') ?
      1 : 0;

    $repo = $request->getValue('repositoryCallsign');
    $path = $request->getValue('path');
    $is_path_query = ($repo && $path) ? 1 : 0;

    if ($is_owner_query + $is_path_query + $is_affiliated_query === 0) {
      // if no search terms are provided, return everything
      $packages = self::queryAll();
    } else if ($is_owner_query + $is_path_query + $is_affiliated_query > 1) {
      // otherwise, exactly one of these should be provided
      throw new ConduitException('ERR-INVALID-USAGE');
    }

    if ($is_affiliated_query) {
      $query = id(new PhabricatorOwnersPackageQuery())
        ->setViewer($request->getUser());

      $query->withOwnerPHIDs(array($request->getValue('userAffiliated')));

      $packages = $query->execute();
    } else if ($is_owner_query) {
      $owner = nonempty(
        $request->getValue('userOwner'),
        $request->getValue('projectOwner'));

      $packages = self::queryByOwner($owner);

    } else if ($is_path_query) {
      $packages = self::queryByPath($repo, $path);
    }

    return self::buildPackageInformationDictionaries($packages);
  }
}
