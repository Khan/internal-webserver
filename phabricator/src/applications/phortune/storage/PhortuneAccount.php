<?php

/**
 * An account represents a purchasing entity. An account may have multiple users
 * on it (e.g., several employees of a company have access to the company
 * account), and a user may have several accounts (e.g., a company account and
 * a personal account).
 */
final class PhortuneAccount extends PhortuneDAO
  implements PhabricatorPolicyInterface {

  protected $name;
  protected $balanceInCents = 0;

  private $memberPHIDs = self::ATTACHABLE;

  public function getConfiguration() {
    return array(
      self::CONFIG_AUX_PHID => true,
    ) + parent::getConfiguration();
  }

  public function generatePHID() {
    return PhabricatorPHID::generateNewPHID(
      PhabricatorPHIDConstants::PHID_TYPE_ACNT);
  }

  public function getMemberPHIDs() {
    return $this->assertAttached($this->memberPHIDs);
  }

  public function attachMemberPHIDs(array $phids) {
    $this->memberPHIDs = $phids;
    return $this;
  }


/* -(  PhabricatorPolicyInterface  )----------------------------------------- */


  public function getCapabilities() {
    return array(
      PhabricatorPolicyCapability::CAN_VIEW,
      PhabricatorPolicyCapability::CAN_EDIT,
    );
  }

  public function getPolicy($capability) {
    if ($this->getPHID() === null) {
      // Allow a user to create an account for themselves.
      return PhabricatorPolicies::POLICY_USER;
    } else {
      return PhabricatorPolicies::POLICY_NOONE;
    }
  }

  public function hasAutomaticCapability($capability, PhabricatorUser $viewer) {
    $members = array_fuse($this->getMemberPHIDs());
    return isset($members[$viewer->getPHID()]);
  }

  public function describeAutomaticCapability($capability) {
    return pht('Members of an account can always view and edit it.');
  }


}
