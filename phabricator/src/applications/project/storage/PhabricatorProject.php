<?php

final class PhabricatorProject extends PhabricatorProjectDAO
  implements
    PhabricatorFlaggableInterface,
    PhabricatorPolicyInterface,
    PhabricatorSubscribableInterface,
    PhabricatorCustomFieldInterface {

  protected $name;
  protected $status = PhabricatorProjectStatus::STATUS_ACTIVE;
  protected $authorPHID;
  protected $subprojectPHIDs = array();
  protected $phrictionSlug;
  protected $profileImagePHID;

  protected $viewPolicy;
  protected $editPolicy;
  protected $joinPolicy;

  private $memberPHIDs = self::ATTACHABLE;
  private $sparseMembers = self::ATTACHABLE;
  private $customFields = self::ATTACHABLE;
  private $profileImageFile = self::ATTACHABLE;

  public static function initializeNewProject(PhabricatorUser $actor) {
    return id(new PhabricatorProject())
      ->setName('')
      ->setAuthorPHID($actor->getPHID())
      ->setViewPolicy(PhabricatorPolicies::POLICY_USER)
      ->setEditPolicy(PhabricatorPolicies::POLICY_USER)
      ->setJoinPolicy(PhabricatorPolicies::POLICY_USER)
      ->attachMemberPHIDs(array());
  }

  public function getCapabilities() {
    return array(
      PhabricatorPolicyCapability::CAN_VIEW,
      PhabricatorPolicyCapability::CAN_EDIT,
      PhabricatorPolicyCapability::CAN_JOIN,
    );
  }

  public function getPolicy($capability) {
    switch ($capability) {
      case PhabricatorPolicyCapability::CAN_VIEW:
        return $this->getViewPolicy();
      case PhabricatorPolicyCapability::CAN_EDIT:
        return $this->getEditPolicy();
      case PhabricatorPolicyCapability::CAN_JOIN:
        return $this->getJoinPolicy();
    }
  }

  public function hasAutomaticCapability($capability, PhabricatorUser $viewer) {

    switch ($capability) {
      case PhabricatorPolicyCapability::CAN_VIEW:
        if ($this->isUserMember($viewer->getPHID())) {
          // Project members can always view a project.
          return true;
        }
        break;
      case PhabricatorPolicyCapability::CAN_EDIT:
        break;
      case PhabricatorPolicyCapability::CAN_JOIN:
        $can_edit = PhabricatorPolicyCapability::CAN_EDIT;
        if (PhabricatorPolicyFilter::hasCapability($viewer, $this, $can_edit)) {
          // Project editors can always join a project.
          return true;
        }
        break;
    }

    return false;
  }

  public function describeAutomaticCapability($capability) {
    switch ($capability) {
      case PhabricatorPolicyCapability::CAN_VIEW:
        return pht("Members of a project can always view it.");
      case PhabricatorPolicyCapability::CAN_JOIN:
        return pht("Users who can edit a project can always join it.");
    }
    return null;
  }

  public function isUserMember($user_phid) {
    if ($this->memberPHIDs !== self::ATTACHABLE) {
      return in_array($user_phid, $this->memberPHIDs);
    }
    return $this->assertAttachedKey($this->sparseMembers, $user_phid);
  }

  public function setIsUserMember($user_phid, $is_member) {
    if ($this->sparseMembers === self::ATTACHABLE) {
      $this->sparseMembers = array();
    }
    $this->sparseMembers[$user_phid] = $is_member;
    return $this;
  }

  public function getConfiguration() {
    return array(
      self::CONFIG_AUX_PHID => true,
      self::CONFIG_SERIALIZATION => array(
        'subprojectPHIDs' => self::SERIALIZATION_JSON,
      ),
    ) + parent::getConfiguration();
  }

  public function generatePHID() {
    return PhabricatorPHID::generateNewPHID(
      PhabricatorProjectPHIDTypeProject::TYPECONST);
  }

  public function attachMemberPHIDs(array $phids) {
    $this->memberPHIDs = $phids;
    return $this;
  }

  public function getMemberPHIDs() {
    return $this->assertAttached($this->memberPHIDs);
  }

  public function setPhrictionSlug($slug) {

    // NOTE: We're doing a little magic here and stripping out '/' so that
    // project pages always appear at top level under projects/ even if the
    // display name is "Hack / Slash" or similar (it will become
    // 'hack_slash' instead of 'hack/slash').

    $slug = str_replace('/', ' ', $slug);
    $slug = PhabricatorSlug::normalize($slug);
    $this->phrictionSlug = $slug;
    return $this;
  }

  public function getFullPhrictionSlug() {
    $slug = $this->getPhrictionSlug();
    return 'projects/'.$slug;
  }

  public function isArchived() {
    return ($this->getStatus() == PhabricatorProjectStatus::STATUS_ARCHIVED);
  }

  public function getProfileImageURI() {
    return $this->getProfileImageFile()->getBestURI();
  }

  public function attachProfileImageFile(PhabricatorFile $file) {
    $this->profileImageFile = $file;
    return $this;
  }

  public function getProfileImageFile() {
    return $this->assertAttached($this->profileImageFile);
  }


/* -(  PhabricatorSubscribableInterface  )----------------------------------- */


  public function isAutomaticallySubscribed($phid) {
    return false;
  }

  public function shouldShowSubscribersProperty() {
    return false;
  }

  public function shouldAllowSubscription($phid) {
    return $this->isUserMember($phid);
  }


/* -(  PhabricatorCustomFieldInterface  )------------------------------------ */


  public function getCustomFieldSpecificationForRole($role) {
    return PhabricatorEnv::getEnvConfig('projects.fields');
  }

  public function getCustomFieldBaseClass() {
    return 'PhabricatorProjectCustomField';
  }

  public function getCustomFields() {
    return $this->assertAttached($this->customFields);
  }

  public function attachCustomFields(PhabricatorCustomFieldAttachment $fields) {
    $this->customFields = $fields;
    return $this;
  }


}
