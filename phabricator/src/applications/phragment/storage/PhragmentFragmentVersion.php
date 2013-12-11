<?php

final class PhragmentFragmentVersion extends PhragmentDAO
  implements PhabricatorPolicyInterface {

  protected $sequence;
  protected $fragmentPHID;
  protected $filePHID;

  private $fragment = self::ATTACHABLE;
  private $file = self::ATTACHABLE;

  public function getConfiguration() {
    return array(
      self::CONFIG_AUX_PHID => true,
    ) + parent::getConfiguration();
  }

  public function generatePHID() {
    return PhabricatorPHID::generateNewPHID(
      PhragmentPHIDTypeFragmentVersion::TYPECONST);
  }

  public function getURI() {
    return '/phragment/version/'.$this->getID().'/';
  }

  public function getFragment() {
    return $this->assertAttached($this->fragment);
  }

  public function attachFragment(PhragmentFragment $fragment) {
    return $this->fragment = $fragment;
  }

  public function getFile() {
    return $this->assertAttached($this->file);
  }

  public function attachFile(PhabricatorFile $file) {
    return $this->file = $file;
  }

  public function getCapabilities() {
    return array(
      PhabricatorPolicyCapability::CAN_VIEW
    );
  }

  public function getPolicy($capability) {
    return $this->getFragment()->getPolicy($capability);
  }

  public function hasAutomaticCapability($capability, PhabricatorUser $viewer) {
    return $this->getFragment()->hasAutomaticCapability($capability, $viewer);
  }

  public function describeAutomaticCapability($capability) {
    return $this->getFragment()->describeAutomaticCapability($capability);
  }

}
