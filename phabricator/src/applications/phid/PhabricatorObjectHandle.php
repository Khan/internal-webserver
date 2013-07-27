<?php

final class PhabricatorObjectHandle
  implements PhabricatorPolicyInterface {

  private $uri;
  private $phid;
  private $type;
  private $name;
  private $fullName;
  private $title;
  private $imageURI;
  private $timestamp;
  private $status = PhabricatorObjectHandleStatus::STATUS_OPEN;
  private $complete;
  private $disabled;

  public function setURI($uri) {
    $this->uri = $uri;
    return $this;
  }

  public function getURI() {
    return $this->uri;
  }

  public function setPHID($phid) {
    $this->phid = $phid;
    return $this;
  }

  public function getPHID() {
    return $this->phid;
  }

  public function setName($name) {
    $this->name = $name;
    return $this;
  }

  public function getName() {
    if ($this->name === null) {
      return pht('Unknown Object (%s)', $this->getTypeName());
    }
    return $this->name;
  }

  public function setStatus($status) {
    $this->status = $status;
    return $this;
  }

  public function getStatus() {
    return $this->status;
  }

  public function setFullName($full_name) {
    $this->fullName = $full_name;
    return $this;
  }

  public function getFullName() {
    if ($this->fullName !== null) {
      return $this->fullName;
    }
    return $this->getName();
  }

  public function setTitle($title) {
    $this->title = $title;
    return $this;
  }

  public function getTitle() {
    return $this->title;
  }

  public function setType($type) {
    $this->type = $type;
    return $this;
  }

  public function getType() {
    return $this->type;
  }

  public function setImageURI($uri) {
    $this->imageURI = $uri;
    return $this;
  }

  public function getImageURI() {
    return $this->imageURI;
  }

  public function setTimestamp($timestamp) {
    $this->timestamp = $timestamp;
    return $this;
  }

  public function getTimestamp() {
    return $this->timestamp;
  }

  public function getTypeName() {
    if ($this->getPHIDType()) {
      return $this->getPHIDType()->getTypeName();
    }

    return $this->getType();
  }


  /**
   * Set whether or not the underlying object is complete. See
   * @{method:isComplete} for an explanation of what it means to be complete.
   *
   * @param bool True if the handle represents a complete object.
   * @return this
   */
  public function setComplete($complete) {
    $this->complete = $complete;
    return $this;
  }


  /**
   * Determine if the handle represents an object which was completely loaded
   * (i.e., the underlying object exists) vs an object which could not be
   * completely loaded (e.g., the type or data for the PHID could not be
   * identified or located).
   *
   * Basically, @{class:PhabricatorObjectHandleData} gives you back a handle for
   * any PHID you give it, but it gives you a complete handle only for valid
   * PHIDs.
   *
   * @return bool True if the handle represents a complete object.
   */
  public function isComplete() {
    return $this->complete;
  }


  /**
   * Set whether or not the underlying object is disabled. See
   * @{method:isDisabled} for an explanation of what it means to be disabled.
   *
   * @param bool True if the handle represents a disabled object.
   * @return this
   */
  public function setDisabled($disabled) {
    $this->disabled = $disabled;
    return $this;
  }


  /**
   * Determine if the handle represents an object which has been disabled --
   * for example, disabled users, archived projects, etc. These objects are
   * complete and exist, but should be excluded from some system interactions
   * (for instance, they usually should not appear in typeaheads, and should
   * not have mail/notifications delivered to or about them).
   *
   * @return bool True if the handle represents a disabled object.
   */
  public function isDisabled() {
    return $this->disabled;
  }


  public function renderLink($name = null) {
    if ($name === null) {
      $name = $this->getLinkName();
    }
    $class = null;
    $title = $this->title;

    if ($this->status != PhabricatorObjectHandleStatus::STATUS_OPEN) {
      $class .= ' handle-status-'.$this->status;
      $title = $title ? $title : $this->status;
    }

    if ($this->disabled) {
      $class .= ' handle-disabled';
      $title = 'disabled'; // Overwrite status.
    }

    return phutil_tag(
      'a',
      array(
        'href'  => $this->getURI(),
        'class' => $class,
        'title' => $title,
      ),
      $name);
  }

  public function getLinkName() {
    switch ($this->getType()) {
      case PhabricatorPeoplePHIDTypeUser::TYPECONST:
        $name = $this->getName();
        break;
      default:
        $name = $this->getFullName();
        break;
    }
    return $name;
  }

  protected function getPHIDType() {
    $types = PhabricatorPHIDType::getAllTypes();
    return idx($types, $this->getType());
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
