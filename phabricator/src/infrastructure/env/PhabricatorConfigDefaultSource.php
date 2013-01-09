<?php

/**
 * Configuration source which reads from defaults defined in the authoritative
 * configuration definitions.
 */
final class PhabricatorConfigDefaultSource
  extends PhabricatorConfigProxySource {

  public function __construct() {
    $options = PhabricatorApplicationConfigOptions::loadAllOptions();
    $options = mpull($options, 'getDefault');
    $this->setSource(new PhabricatorConfigDictionarySource($options));
  }

}
