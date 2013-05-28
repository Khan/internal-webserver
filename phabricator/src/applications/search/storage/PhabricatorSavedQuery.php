<?php

/**
 * @group search
 */
final class PhabricatorSavedQuery extends PhabricatorSearchDAO
  implements PhabricatorPolicyInterface {

  protected $parameters = array();
  protected $queryKey = "";
  protected $engineClassName = "PhabricatorPasteSearchEngine";

  public function getConfiguration() {
    return array(
      self::CONFIG_SERIALIZATION => array(
        'parameters' => self::SERIALIZATION_JSON), )
      + parent::getConfiguration();
  }

  public function setParameter($key, $value) {
    $this->parameters[$key] = $value;
    return $this;
  }

  public function getParameter($key, $default = null) {
    return idx($this->parameters, $key, $default);
  }

  public function save() {
    if ($this->getEngineClassName() === null) {
      throw new Exception(pht("Engine class is null."));
    }

    // Instantiate the engine to make sure it's valid.
    $this->newEngine();

    $serial = $this->getEngineClassName().serialize($this->parameters);
    $this->queryKey = PhabricatorHash::digestForIndex($serial);

    return parent::save();
  }

  public function newEngine() {
    return newv($this->getEngineClassName(), array());
  }


/* -(  PhabricatorPolicyInterface  )----------------------------------------- */


  public function getCapabilities() {
    return array(
      PhabricatorPolicyCapability::CAN_VIEW,
    );
  }

  public function getPolicy($capability) {
    return PhabricatorPolicies::POLICY_PUBLIC;
  }

  public function hasAutomaticCapability($capability, PhabricatorUser $viewer) {
    return false;
  }

}
