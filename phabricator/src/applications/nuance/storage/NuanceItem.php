<?php

final class NuanceItem
  extends NuanceDAO
  implements PhabricatorPolicyInterface {

  protected $ownerPHID;
  protected $requestorPHID;
  protected $sourcePHID;
  protected $sourceLabel;
  protected $data;
  protected $mailKey;
  protected $dateNuanced;

  public function getConfiguration() {
    return array(
      self::CONFIG_AUX_PHID => true,
      self::CONFIG_SERIALIZATION => array(
        'data' => self::SERIALIZATION_JSON,
      ),
    ) + parent::getConfiguration();
  }

  public function generatePHID() {
    return PhabricatorPHID::generateNewPHID(
      NuancePHIDTypeItem::TYPECONST);
  }

  public function save() {
    if (!$this->getMailKey()) {
      $this->setMailKey(Filesystem::readRandomCharacters(20));
    }
    return parent::save();
  }

  public function getURI() {
    return '/nuance/item/view/'.$this->getID().'/';
  }

  public function getLabel(PhabricatorUser $viewer) {
    // this is generated at the time the item is created based on
    // the configuration from the item source. It is typically
    // something like 'Twitter'.
    $source_label = $this->getSourceLabel();

    return pht(
      'Item via %s @ %s.',
      $source_label,
      phabricator_datetime($this->getDateCreated(), $viewer));
  }

  public function getRequestor() {
    return $this->assertAttached($this->requestor);
  }

  public function attachRequestor(NuanceRequestor $requestor) {
    return $this->requestor = $requestor;
  }

  public function getSource() {
    return $this->assertAttached($this->source);
  }

  public function attachSource(NuanceSource $source) {
    $this->source = $source;
  }

  public function getCapabilities() {
    return array(
      PhabricatorPolicyCapability::CAN_VIEW,
      PhabricatorPolicyCapability::CAN_EDIT,
    );
  }

  public function getPolicy($capability) {
    // TODO - this should be based on the queues the item currently resides in
    return PhabricatorPolicies::POLICY_NOONE;
  }

  public function hasAutomaticCapability($capability, PhabricatorUser $viewer) {
    // TODO - requestors should get auto access too!
    return $viewer->getPHID() == $this->ownerPHID;
  }

  public function describeAutomaticCapability($capability) {
    switch ($capability) {
      case PhabricatorPolicyCapability::CAN_VIEW:
        return pht('Owners of an item can always view it.');
      case PhabricatorPolicyCapability::CAN_EDIT:
        return pht('Owners of an item can always edit it.');
    }
    return null;
  }

}
