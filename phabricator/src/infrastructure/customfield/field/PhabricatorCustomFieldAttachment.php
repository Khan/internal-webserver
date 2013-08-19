<?php

/**
 * Convenience class which simplifies the implementation of
 * @{interface:PhabricatorCustomFieldInterface} by obscuring the details of how
 * custom fields are stored.
 *
 * Generally, you should not use this class directly. It is used by
 * @{class:PhabricatorCustomField} to manage field storage on objects.
 */
final class PhabricatorCustomFieldAttachment {

  private $lists = array();

  public function addCustomFieldList($role, PhabricatorCustomFieldList $list) {
    $this->lists[$role] = $list;
    return $this;
  }

  public function getCustomFieldList($role) {
    if (empty($this->lists[$role])) {
      throw new PhabricatorCustomFieldNotAttachedException(
        "Role list '{$role}' is not available!");
    }
    return $this->lists[$role];
  }

}
