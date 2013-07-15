<?php

final class PhabricatorUserRolesField
  extends PhabricatorUserCustomField {

  private $value;

  public function getFieldKey() {
    return 'user:roles';
  }

  public function getFieldName() {
    return pht('Roles');
  }

  public function getFieldDescription() {
    return pht('Shows roles like "Administrator" and "Disabled".');
  }

  public function shouldAppearInPropertyView() {
    return true;
  }

  public function renderPropertyViewValue() {
    $user = $this->getObject();

    $roles = array();
    if ($user->getIsAdmin()) {
      $roles[] = pht('Administrator');
    }
    if ($user->getIsDisabled()) {
      $roles[] = pht('Disabled');
    }
    if ($user->getIsSystemAgent()) {
      $roles[] = pht('Bot');
    }

    if ($roles) {
      return implode(', ', $roles);
    }

    return null;
  }

}
