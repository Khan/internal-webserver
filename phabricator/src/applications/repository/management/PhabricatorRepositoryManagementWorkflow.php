<?php

abstract class PhabricatorRepositoryManagementWorkflow
  extends PhabricatorManagementWorkflow {

  protected function loadRepositories(PhutilArgumentParser $args, $param) {
    $callsigns = $args->getArg($param);

    if (!$callsigns) {
      return null;
    }

    $repos = id(new PhabricatorRepositoryQuery())
      ->setViewer($this->getViewer())
      ->withCallsigns($callsigns)
      ->execute();

    $repos = mpull($repos, null, 'getCallsign');
    foreach ($callsigns as $callsign) {
      if (empty($repos[$callsign])) {
        throw new PhutilArgumentUsageException(
          "No repository with callsign '{$callsign}' exists!");
      }
    }

    return $repos;
  }

  protected function loadCommits(PhutilArgumentParser $args, $param) {
    $names = $args->getArg($param);
    if (!$names) {
      return null;
    }

    $query = id(new DiffusionCommitQuery())
      ->setViewer($this->getViewer())
      ->withIdentifiers($names);

    $query->execute();
    $map = $query->getIdentifierMap();

    foreach ($names as $name) {
      if (empty($map[$name])) {
        throw new PhutilArgumentUsageException(
          pht('Commit "%s" does not exist or is ambiguous.', $name));
      }
    }

    return $map;
  }

}
