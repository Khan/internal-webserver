<?php

/**
 * @group repository
 */
final class PhabricatorRepositoryConfigOptions
  extends PhabricatorApplicationConfigOptions {

  public function getName() {
    return pht('Repositories');
  }

  public function getDescription() {
    return pht('Configure repositories.');
  }

  public function getOptions() {
    return array(
      $this->newOption('repository.default-local-path', 'string', '/var/repo/')
        ->setSummary(
          pht("Default location to store local copies of repositories."))
        ->setDescription(
          pht(
            "The default location in which to store working copies and other ".
            "data about repositories. Phabricator will control and manage ".
            "data here, so you should **not** choose an existing directory ".
            "full of data you care about.")),
    );
  }

}
