<?php

/**
 * @group legalpad
 */
final class PhabricatorLegalpadConfigOptions
  extends PhabricatorApplicationConfigOptions {

  public function getName() {
    return pht('Legalpad');
  }

  public function getDescription() {
    return pht('Configure Legalpad.');
  }

  public function getOptions() {
    return array(
      $this->newOption(
        'metamta.legalpad.subject-prefix',
        'string',
        '[Legalpad]')
        ->setDescription(pht('Subject prefix for Legalpad email.'))
    );
  }

}
