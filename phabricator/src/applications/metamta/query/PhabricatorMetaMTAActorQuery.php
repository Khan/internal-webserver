<?php

final class PhabricatorMetaMTAActorQuery extends PhabricatorQuery {

  private $phids = array();
  private $viewer;

  public function setViewer(PhabricatorUser $viewer) {
    $this->viewer = $viewer;
    return $this;
  }

  public function getViewer() {
    return $this->viewer;
  }

  public function withPHIDs(array $phids) {
    $this->phids = $phids;
    return $this;
  }

  public function execute() {
    $phids = array_fuse($this->phids);
    $actors = array();
    $type_map = array();
    foreach ($phids as $phid) {
      $type_map[phid_get_type($phid)][] = $phid;
      $actors[$phid] = id(new PhabricatorMetaMTAActor())->setPHID($phid);
    }

    foreach ($type_map as $type => $phids) {
      switch ($type) {
        case PhabricatorPHIDConstants::PHID_TYPE_USER:
          $this->loadUserActors($actors, $phids);
          break;
        case PhabricatorPHIDConstants::PHID_TYPE_XUSR:
          $this->loadExternalUserActors($actors, $phids);
          break;
        case PhabricatorPHIDConstants::PHID_TYPE_MLST:
          $this->loadMailingListActors($actors, $phids);
          break;
        default:
          $this->loadUnknownActors($actors, $phids);
          break;
      }
    }

    return $actors;
  }

  private function loadUserActors(array $actors, array $phids) {
    assert_instances_of($actors, 'PhabricatorMetaMTAActor');

    $emails = id(new PhabricatorUserEmail())->loadAllWhere(
      'userPHID IN (%Ls) AND isPrimary = 1',
      $phids);
    $emails = mpull($emails, null, 'getUserPHID');

    $users = id(new PhabricatorPeopleQuery())
      ->setViewer($this->getViewer())
      ->withPHIDs($phids)
      ->execute();
    $users = mpull($users, null, 'getPHID');

    foreach ($phids as $phid) {
      $actor = $actors[$phid];

      $user = idx($users, $phid);
      if (!$user) {
        $actor->setUndeliverable(
          pht('Unable to load user record for this PHID.'));
      } else {
        $actor->setName($this->getUserName($user));
        if ($user->getIsDisabled()) {
          $actor->setUndeliverable(
            pht('This user is disabled; disabled users do not receive mail.'));
        }
        if ($user->getIsSystemAgent()) {
          $actor->setUndeliverable(
            pht('This user is a bot; bot accounts do not receive mail.'));
        }
      }

      $email = idx($emails, $phid);
      if (!$email) {
        $actor->setUndeliverable(
          pht('Unable to load email record for this PHID.'));
      } else {
        $actor->setEmailAddress($email->getAddress());
      }
    }
  }

  private function loadExternalUserActors(array $actors, array $phids) {
    assert_instances_of($actors, 'PhabricatorMetaMTAActor');

    $xusers = id(new PhabricatorExternalAccountQuery())
      ->setViewer($this->getViewer())
      ->withPHIDs($phids)
      ->execute();
    $xusers = mpull($xusers, null, 'getPHID');

    foreach ($phids as $phid) {
      $actor = $actors[$phid];

      $xuser = idx($xusers, $phid);
      if (!$xuser) {
        $actor->setUndeliverable(
          pht('Unable to load external user record for this PHID.'));
        continue;
      }

      $actor->setName($xuser->getDisplayName());

      if ($xuser->getAccountType() != 'email') {
        $actor->setUndeliverable(
          pht(
            'Only external accounts of type "email" are deliverable; this '.
            'account has a different type.'));
        continue;
      }

      $actor->setEmailAddress($xuser->getAccountID());
    }
  }

  private function loadMailingListActors(array $actors, array $phids) {
    assert_instances_of($actors, 'PhabricatorMetaMTAActor');

    $lists = id(new PhabricatorMetaMTAMailingList())->loadAllWhere(
      'phid IN (%Ls)',
      $phids);
    $lists = mpull($lists, null, 'getPHID');

    foreach ($phids as $phid) {
      $actor = $actors[$phid];

      $list = idx($lists, $phid);
      if (!$list) {
        $actor->setUndeliverable(
          pht(
            'Unable to load mailing list record for this PHID.'));
        continue;
      }

      $actor->setName($list->getName());
      $actor->setEmailAddress($list->getEmail());
    }
  }

  private function loadUnknownActors(array $actors, array $phids) {
    foreach ($phids as $phid) {
      $actor = $actors[$phid];
      $actor->setUndeliverable(pht('This PHID type is not mailable.'));
    }
  }


  /**
   * Small helper function to make sure we format the username properly as
   * specified by the `metamta.user-address-format` configuration value.
   */
  private function getUserName(PhabricatorUser $user) {
    $format = PhabricatorEnv::getEnvConfig('metamta.user-address-format');

    switch ($format) {
      case 'short':
        $name = $user->getUserName();
        break;
      case 'real':
        $name = $user->getRealName();
        break;
      case 'full':
      default:
        $name = $user->getFullName();
        break;
    }

    return $name;
  }

}
