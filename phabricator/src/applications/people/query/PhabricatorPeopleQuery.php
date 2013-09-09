<?php

final class PhabricatorPeopleQuery
  extends PhabricatorCursorPagedPolicyAwareQuery {

  private $usernames;
  private $realnames;
  private $emails;
  private $phids;
  private $ids;
  private $dateCreatedAfter;
  private $dateCreatedBefore;
  private $isAdmin;
  private $isSystemAgent;
  private $isDisabled;
  private $nameLike;

  private $needPrimaryEmail;
  private $needProfile;
  private $needProfileImage;
  private $needStatus;

  public function withIDs(array $ids) {
    $this->ids = $ids;
    return $this;
  }

  public function withPHIDs(array $phids) {
    $this->phids = $phids;
    return $this;
  }

  public function withEmails(array $emails) {
    $this->emails = $emails;
    return $this;
  }

  public function withRealnames(array $realnames) {
    $this->realnames = $realnames;
    return $this;
  }

  public function withUsernames(array $usernames) {
    $this->usernames = $usernames;
    return $this;
  }

  public function withDateCreatedBefore($date_created_before) {
    $this->dateCreatedBefore = $date_created_before;
    return $this;
  }

  public function withDateCreatedAfter($date_created_after) {
    $this->dateCreatedAfter = $date_created_after;
    return $this;
  }

  public function withIsAdmin($admin) {
    $this->isAdmin = $admin;
    return $this;
  }

  public function withIsSystemAgent($system_agent) {
    $this->isSystemAgent = $system_agent;
    return $this;
  }

  public function withIsDisabled($disabled) {
    $this->isDisabled = $disabled;
    return $this;
  }

  public function withNameLike($like) {
    $this->nameLike = $like;
    return $this;
  }

  public function needPrimaryEmail($need) {
    $this->needPrimaryEmail = $need;
    return $this;
  }

  public function needProfile($need) {
    $this->needProfile = $need;
    return $this;
  }

  public function needProfileImage($need) {
    $this->needProfileImage = $need;
    return $this;
  }

  public function needStatus($need) {
    $this->needStatus = $need;
    return $this;
  }

  public function loadPage() {
    $table  = new PhabricatorUser();
    $conn_r = $table->establishConnection('r');

    $data = queryfx_all(
      $conn_r,
      'SELECT * FROM %T user %Q %Q %Q %Q',
      $table->getTableName(),
      $this->buildJoinsClause($conn_r),
      $this->buildWhereClause($conn_r),
      $this->buildOrderClause($conn_r),
      $this->buildLimitClause($conn_r));

    if ($this->needPrimaryEmail) {
      $table->putInSet(new LiskDAOSet());
    }

    $users = $table->loadAllFromArray($data);

    if ($this->needProfile) {
      $user_list = mpull($users, null, 'getPHID');
      $profiles = new PhabricatorUserProfile();
      $profiles = $profiles->loadAllWhere('userPHID IN (%Ls)',
        array_keys($user_list));

      $profiles = mpull($profiles, null, 'getUserPHID');
      foreach ($user_list as $user_phid => $user) {
        $profile = idx($profiles, $user_phid);
        if (!$profile) {
          $profile = new PhabricatorUserProfile();
          $profile->setUserPHID($user_phid);
        }

        $user->attachUserProfile($profile);
      }
    }

    if ($this->needProfileImage) {
      $user_profile_file_phids = mpull($users, 'getProfileImagePHID');
      $user_profile_file_phids = array_filter($user_profile_file_phids);
      if ($user_profile_file_phids) {
        $files = id(new PhabricatorFileQuery())
          ->setViewer($this->getViewer())
          ->withPHIDs($user_profile_file_phids)
          ->execute();
        $files = mpull($files, null, 'getPHID');
      } else {
        $files = array();
      }
      foreach ($users as $user) {
        $image_phid = $user->getProfileImagePHID();
        if (isset($files[$image_phid])) {
          $profile_image_uri = $files[$image_phid]->getBestURI();
        } else {
          $profile_image_uri = PhabricatorUser::getDefaultProfileImageURI();
        }
        $user->attachProfileImageURI($profile_image_uri);
      }
    }

    if ($this->needStatus) {
      $user_list = mpull($users, null, 'getPHID');
      $statuses = id(new PhabricatorUserStatus())->loadCurrentStatuses(
        array_keys($user_list));
      foreach ($user_list as $phid => $user) {
        $status = idx($statuses, $phid);
        if ($status) {
          $user->attachStatus($status);
        }
      }
    }

    return $users;
  }

  private function buildJoinsClause($conn_r) {
    $joins = array();

    if ($this->emails) {
      $email_table = new PhabricatorUserEmail();
      $joins[] = qsprintf(
        $conn_r,
        'JOIN %T email ON email.userPHID = user.PHID',
        $email_table->getTableName());
    }

    $joins = implode(' ', $joins);
    return  $joins;
  }

  private function buildWhereClause($conn_r) {
    $where = array();

    if ($this->usernames) {
      $where[] = qsprintf(
        $conn_r,
        'user.userName IN (%Ls)',
        $this->usernames);
    }

    if ($this->emails) {
      $where[] = qsprintf(
        $conn_r,
        'email.address IN (%Ls)',
        $this->emails);
    }

    if ($this->realnames) {
      $where[] = qsprintf(
        $conn_r,
        'user.realName IN (%Ls)',
        $this->realnames);
    }

    if ($this->phids) {
      $where[] = qsprintf(
        $conn_r,
        'user.phid IN (%Ls)',
        $this->phids);
    }

    if ($this->ids) {
      $where[] = qsprintf(
        $conn_r,
        'user.id IN (%Ld)',
        $this->ids);
    }

    if ($this->dateCreatedAfter) {
      $where[] = qsprintf(
        $conn_r,
        'user.dateCreated >= %d',
        $this->dateCreatedAfter);
    }

    if ($this->dateCreatedBefore) {
      $where[] = qsprintf(
        $conn_r,
        'user.dateCreated <= %d',
        $this->dateCreatedBefore);
    }

    if ($this->isAdmin) {
      $where[] = qsprintf(
        $conn_r,
        'user.isAdmin = 1');
    }

    if ($this->isDisabled) {
      $where[] = qsprintf(
        $conn_r,
        'user.isDisabled = 1');
    }

    if ($this->isSystemAgent) {
      $where[] = qsprintf(
        $conn_r,
        'user.isSystemAgent = 1');
    }

    if (strlen($this->nameLike)) {
      $where[] = qsprintf(
        $conn_r,
        'user.username LIKE %~ OR user.realname LIKE %~',
        $this->nameLike,
        $this->nameLike);
    }

    return $this->formatWhereClause($where);
  }

  protected function getPagingColumn() {
    return 'user.id';
  }

}
