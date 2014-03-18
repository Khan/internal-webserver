<?php

final class PhabricatorOAuthClientAuthorization
  extends PhabricatorOAuthServerDAO
  implements PhabricatorPolicyInterface {

  protected $userPHID;
  protected $clientPHID;
  protected $scope;

  private $client = self::ATTACHABLE;

  public function getScopeString() {
    $scope = $this->getScope();
    $scopes = array_keys($scope);
    sort($scopes);
    return implode(' ', $scopes);
  }

  public function getConfiguration() {
    return array(
      self::CONFIG_AUX_PHID => true,
      self::CONFIG_SERIALIZATION => array(
        'scope' => self::SERIALIZATION_JSON,
      ),
    ) + parent::getConfiguration();
  }

  public function generatePHID() {
    return PhabricatorPHID::generateNewPHID(
      PhabricatorOAuthServerPHIDTypeClientAuthorization::TYPECONST);
  }

  public function getClient() {
    return $this->assertAttached($this->client);
  }

  public function attachClient(PhabricatorOAuthServerClient $client) {
    $this->client = $client;
    return $this;
  }

/* -(  PhabricatorPolicyInterface  )----------------------------------------- */


  public function getCapabilities() {
    return array(
      PhabricatorPolicyCapability::CAN_VIEW,
    );
  }

  public function getPolicy($capability) {
    switch ($capability) {
      case PhabricatorPolicyCapability::CAN_VIEW:
        return PhabricatorPolicies::POLICY_NOONE;
    }
  }

  public function hasAutomaticCapability($capability, PhabricatorUser $viewer) {
    return ($viewer->getPHID() == $this->getUserPHID());
  }

  public function describeAutomaticCapability($capability) {
    return pht('Authorizations can only be viewed by the authorizing user.');
  }

}
