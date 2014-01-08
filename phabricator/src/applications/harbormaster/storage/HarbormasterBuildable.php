<?php

final class HarbormasterBuildable extends HarbormasterDAO
  implements
    PhabricatorPolicyInterface,
    HarbormasterBuildableInterface {

  protected $buildablePHID;
  protected $containerPHID;
  protected $buildStatus;
  protected $buildableStatus;
  protected $isManualBuildable;

  private $buildableObject = self::ATTACHABLE;
  private $containerObject = self::ATTACHABLE;
  private $buildableHandle = self::ATTACHABLE;
  private $containerHandle = self::ATTACHABLE;
  private $builds = self::ATTACHABLE;

  const STATUS_WHATEVER = 'whatever';

  public static function initializeNewBuildable(PhabricatorUser $actor) {
    return id(new HarbormasterBuildable())
      ->setIsManualBuildable(0)
      ->setBuildStatus(self::STATUS_WHATEVER)
      ->setBuildableStatus(self::STATUS_WHATEVER);
  }

  public function getMonogram() {
    return 'B'.$this->getID();
  }

  /**
   * Returns an existing buildable for the object's PHID or creates a
   * new buildable implicitly if needed.
   */
  public static function createOrLoadExisting(
    PhabricatorUser $actor,
    $buildable_object_phid,
    $container_object_phid) {

    $buildable = id(new HarbormasterBuildableQuery())
      ->setViewer($actor)
      ->withBuildablePHIDs(array($buildable_object_phid))
      ->withManualBuildables(false)
      ->setLimit(1)
      ->executeOne();
    if ($buildable) {
      return $buildable;
    }
    $buildable = HarbormasterBuildable::initializeNewBuildable($actor)
      ->setBuildablePHID($buildable_object_phid)
      ->setContainerPHID($container_object_phid);
    $buildable->save();
    return $buildable;
  }

  /**
   * Looks up the plan PHIDs and applies the plans to the specified
   * object identified by it's PHID.
   */
  public static function applyBuildPlans(
    $phid,
    $container_phid,
    array $plan_phids) {

    if (count($plan_phids) === 0) {
      return;
    }

    // Skip all of this logic if the Harbormaster application
    // isn't currently installed.

    $harbormaster_app = 'PhabricatorApplicationHarbormaster';
    if (!PhabricatorApplication::isClassInstalled($harbormaster_app)) {
      return;
    }

    $buildable = HarbormasterBuildable::createOrLoadExisting(
      PhabricatorUser::getOmnipotentUser(),
      $phid,
      $container_phid);

    $plans = id(new HarbormasterBuildPlanQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs($plan_phids)
      ->execute();
    foreach ($plans as $plan) {
      if ($plan->isDisabled()) {
        // TODO: This should be communicated more clearly -- maybe we should
        // create the build but set the status to "disabled" or "derelict".
        continue;
      }

      $buildable->applyPlan($plan);
    }
  }

  public function applyPlan(HarbormasterBuildPlan $plan) {
    $viewer = PhabricatorUser::getOmnipotentUser();
    $build = HarbormasterBuild::initializeNewBuild($viewer)
      ->setBuildablePHID($this->getPHID())
      ->setBuildPlanPHID($plan->getPHID())
      ->setBuildStatus(HarbormasterBuild::STATUS_PENDING)
      ->save();

    PhabricatorWorker::scheduleTask(
      'HarbormasterBuildWorker',
      array(
        'buildID' => $build->getID()
      ));

    return $this;
  }

  public function getConfiguration() {
    return array(
      self::CONFIG_AUX_PHID => true,
    ) + parent::getConfiguration();
  }

  public function generatePHID() {
    return PhabricatorPHID::generateNewPHID(
      HarbormasterPHIDTypeBuildable::TYPECONST);
  }

  public function attachBuildableObject($buildable_object) {
    $this->buildableObject = $buildable_object;
    return $this;
  }

  public function getBuildableObject() {
    return $this->assertAttached($this->buildableObject);
  }

  public function attachContainerObject($container_object) {
    $this->containerObject = $container_object;
    return $this;
  }

  public function getContainerObject() {
    return $this->assertAttached($this->containerObject);
  }

  public function attachContainerHandle($container_handle) {
    $this->containerHandle = $container_handle;
    return $this;
  }

  public function getContainerHandle() {
    return $this->assertAttached($this->containerHandle);
  }

  public function attachBuildableHandle($buildable_handle) {
    $this->buildableHandle = $buildable_handle;
    return $this;
  }

  public function getBuildableHandle() {
    return $this->assertAttached($this->buildableHandle);
  }

  public function attachBuilds(array $builds) {
    assert_instances_of($builds, 'HarbormasterBuild');
    $this->builds = $builds;
    return $this;
  }

  public function getBuilds() {
    return $this->assertAttached($this->builds);
  }


/* -(  PhabricatorPolicyInterface  )----------------------------------------- */


  public function getCapabilities() {
    return array(
      PhabricatorPolicyCapability::CAN_VIEW,
      PhabricatorPolicyCapability::CAN_EDIT,
    );
  }

  public function getPolicy($capability) {
    return $this->getBuildableObject()->getPolicy($capability);
  }

  public function hasAutomaticCapability($capability, PhabricatorUser $viewer) {
    return $this->getBuildableObject()->hasAutomaticCapability(
      $capability,
      $viewer);
  }

  public function describeAutomaticCapability($capability) {
    return pht('A buildable inherits policies from the underlying object.');
  }



/* -(  HarbormasterBuildableInterface  )------------------------------------- */


  public function getHarbormasterBuildablePHID() {
    // NOTE: This is essentially just for convenience, as it allows you create
    // a copy of a buildable by specifying `B123` without bothering to go
    // look up the underlying object.
    return $this->getBuildablePHID();
  }

  public function getHarbormasterContainerPHID() {
    return $this->getContainerPHID();
  }


}
