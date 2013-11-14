<?php

/**
 * Editor class for creating and adjusting users. This class guarantees data
 * integrity and writes logs when user information changes.
 *
 * @task config     Configuration
 * @task edit       Creating and Editing Users
 * @task role       Editing Roles
 * @task email      Adding, Removing and Changing Email
 * @task internal   Internals
 */
final class PhabricatorUserEditor extends PhabricatorEditor {

  private $logs = array();


/* -(  Creating and Editing Users  )----------------------------------------- */


  /**
   * @task edit
   */
  public function createNewUser(
    PhabricatorUser $user,
    PhabricatorUserEmail $email) {

    if ($user->getID()) {
      throw new Exception("User has already been created!");
    }

    if ($email->getID()) {
      throw new Exception("Email has already been created!");
    }

    if (!PhabricatorUser::validateUsername($user->getUsername())) {
      $valid = PhabricatorUser::describeValidUsername();
      throw new Exception("Username is invalid! {$valid}");
    }

    // Always set a new user's email address to primary.
    $email->setIsPrimary(1);

    // If the primary address is already verified, also set the verified flag
    // on the user themselves.
    if ($email->getIsVerified()) {
      $user->setIsEmailVerified(1);
    }

    $this->willAddEmail($email);

    $user->openTransaction();
      try {
        $user->save();
        $email->setUserPHID($user->getPHID());
        $email->save();
      } catch (AphrontQueryDuplicateKeyException $ex) {
        // We might have written the user but failed to write the email; if
        // so, erase the IDs we attached.
        $user->setID(null);
        $user->setPHID(null);

        $user->killTransaction();
        throw $ex;
      }

      $log = PhabricatorUserLog::newLog(
        $this->requireActor(),
        $user,
        PhabricatorUserLog::ACTION_CREATE);
      $log->setNewValue($email->getAddress());
      $log->save();

    $user->saveTransaction();

    return $this;
  }


  /**
   * @task edit
   */
  public function updateUser(
    PhabricatorUser $user,
    PhabricatorUserEmail $email = null) {
    if (!$user->getID()) {
      throw new Exception("User has not been created yet!");
    }

    $user->openTransaction();
      $user->save();
      if ($email) {
        $email->save();
      }

      $log = PhabricatorUserLog::newLog(
        $this->requireActor(),
        $user,
        PhabricatorUserLog::ACTION_EDIT);
      $log->save();

    $user->saveTransaction();

    return $this;
  }


  /**
   * @task edit
   */
  public function changePassword(
    PhabricatorUser $user,
    PhutilOpaqueEnvelope $envelope) {

    if (!$user->getID()) {
      throw new Exception("User has not been created yet!");
    }

    $user->openTransaction();
      $user->reload();

      $user->setPassword($envelope);
      $user->save();

      $log = PhabricatorUserLog::newLog(
        $this->requireActor(),
        $user,
        PhabricatorUserLog::ACTION_CHANGE_PASSWORD);
      $log->save();

    $user->saveTransaction();
  }


  /**
   * @task edit
   */
  public function changeUsername(PhabricatorUser $user, $username) {
    $actor = $this->requireActor();

    if (!$user->getID()) {
      throw new Exception("User has not been created yet!");
    }

    if (!PhabricatorUser::validateUsername($username)) {
      $valid = PhabricatorUser::describeValidUsername();
      throw new Exception("Username is invalid! {$valid}");
    }

    $old_username = $user->getUsername();

    $user->openTransaction();
      $user->reload();
      $user->setUsername($username);

      try {
        $user->save();
      } catch (AphrontQueryDuplicateKeyException $ex) {
        $user->setUsername($old_username);
        $user->killTransaction();
        throw $ex;
      }

      $log = PhabricatorUserLog::newLog(
        $actor,
        $user,
        PhabricatorUserLog::ACTION_CHANGE_USERNAME);
      $log->setOldValue($old_username);
      $log->setNewValue($username);
      $log->save();

    $user->saveTransaction();

    $user->sendUsernameChangeEmail($actor, $old_username);
  }


/* -(  Editing Roles  )------------------------------------------------------ */


  /**
   * @task role
   */
  public function makeAdminUser(PhabricatorUser $user, $admin) {
    $actor = $this->requireActor();

    if (!$user->getID()) {
      throw new Exception("User has not been created yet!");
    }

    $user->openTransaction();
      $user->beginWriteLocking();

        $user->reload();
        if ($user->getIsAdmin() == $admin) {
          $user->endWriteLocking();
          $user->killTransaction();
          return $this;
        }

        $log = PhabricatorUserLog::newLog(
          $actor,
          $user,
          PhabricatorUserLog::ACTION_ADMIN);
        $log->setOldValue($user->getIsAdmin());
        $log->setNewValue($admin);

        $user->setIsAdmin($admin);
        $user->save();

        $log->save();

      $user->endWriteLocking();
    $user->saveTransaction();

    return $this;
  }

  /**
   * @task role
   */
  public function makeSystemAgentUser(PhabricatorUser $user, $system_agent) {
    $actor = $this->requireActor();

    if (!$user->getID()) {
      throw new Exception("User has not been created yet!");
    }

    $user->openTransaction();
      $user->beginWriteLocking();

        $user->reload();
        if ($user->getIsSystemAgent() == $system_agent) {
          $user->endWriteLocking();
          $user->killTransaction();
          return $this;
        }

        $log = PhabricatorUserLog::newLog(
          $actor,
          $user,
          PhabricatorUserLog::ACTION_SYSTEM_AGENT);
        $log->setOldValue($user->getIsSystemAgent());
        $log->setNewValue($system_agent);

        $user->setIsSystemAgent($system_agent);
        $user->save();

        $log->save();

      $user->endWriteLocking();
    $user->saveTransaction();

    return $this;
  }


  /**
   * @task role
   */
  public function disableUser(PhabricatorUser $user, $disable) {
    $actor = $this->requireActor();

    if (!$user->getID()) {
      throw new Exception("User has not been created yet!");
    }

    $user->openTransaction();
      $user->beginWriteLocking();

        $user->reload();
        if ($user->getIsDisabled() == $disable) {
          $user->endWriteLocking();
          $user->killTransaction();
          return $this;
        }

        $log = PhabricatorUserLog::newLog(
          $actor,
          $user,
          PhabricatorUserLog::ACTION_DISABLE);
        $log->setOldValue($user->getIsDisabled());
        $log->setNewValue($disable);

        $user->setIsDisabled($disable);
        $user->save();

        $log->save();

      $user->endWriteLocking();
    $user->saveTransaction();

    return $this;
  }


  /**
   * @task role
   */
  public function approveUser(PhabricatorUser $user, $approve) {
    $actor = $this->requireActor();

    if (!$user->getID()) {
      throw new Exception("User has not been created yet!");
    }

    $user->openTransaction();
      $user->beginWriteLocking();

        $user->reload();
        if ($user->getIsApproved() == $approve) {
          $user->endWriteLocking();
          $user->killTransaction();
          return $this;
        }

        $log = PhabricatorUserLog::newLog(
          $actor,
          $user,
          PhabricatorUserLog::ACTION_APPROVE);
        $log->setOldValue($user->getIsApproved());
        $log->setNewValue($approve);

        $user->setIsApproved($approve);
        $user->save();

        $log->save();

      $user->endWriteLocking();
    $user->saveTransaction();

    return $this;
  }

  /**
   * @task role
   */
  public function deleteUser(PhabricatorUser $user, $disable) {
    $actor = $this->requireActor();

    if (!$user->getID()) {
      throw new Exception("User has not been created yet!");
    }

    if ($actor->getPHID() == $user->getPHID()) {
      throw new Exception("You can not delete yourself!");
    }

    $user->openTransaction();
      $externals = id(new PhabricatorExternalAccount())->loadAllWhere(
        'userPHID = %s',
        $user->getPHID());
      foreach ($externals as $external) {
        $external->delete();
      }

      $prefs = id(new PhabricatorUserPreferences())->loadAllWhere(
        'userPHID = %s',
        $user->getPHID());
      foreach ($prefs as $pref) {
        $pref->delete();
      }

      $profiles = id(new PhabricatorUserProfile())->loadAllWhere(
        'userPHID = %s',
        $user->getPHID());
      foreach ($profiles as $profile) {
        $profile->delete();
      }

      $keys = id(new PhabricatorUserSSHKey())->loadAllWhere(
        'userPHID = %s',
        $user->getPHID());
      foreach ($keys as $key) {
        $key->delete();
      }

      $emails = id(new PhabricatorUserEmail())->loadAllWhere(
        'userPHID = %s',
        $user->getPHID());
      foreach ($emails as $email) {
        $email->delete();
      }

      $log = PhabricatorUserLog::newLog(
        $actor,
        $user,
        PhabricatorUserLog::ACTION_DELETE);
      $log->save();

      $user->delete();

    $user->saveTransaction();

    return $this;
  }


/* -(  Adding, Removing and Changing Email  )-------------------------------- */


  /**
   * @task email
   */
  public function addEmail(
    PhabricatorUser $user,
    PhabricatorUserEmail $email) {

    $actor = $this->requireActor();

    if (!$user->getID()) {
      throw new Exception("User has not been created yet!");
    }
    if ($email->getID()) {
      throw new Exception("Email has already been created!");
    }

    // Use changePrimaryEmail() to change primary email.
    $email->setIsPrimary(0);
    $email->setUserPHID($user->getPHID());

    $this->willAddEmail($email);

    $user->openTransaction();
      $user->beginWriteLocking();

        $user->reload();

        try {
          $email->save();
        } catch (AphrontQueryDuplicateKeyException $ex) {
          $user->endWriteLocking();
          $user->killTransaction();

          throw $ex;
        }

        $log = PhabricatorUserLog::newLog(
          $actor,
          $user,
          PhabricatorUserLog::ACTION_EMAIL_ADD);
        $log->setNewValue($email->getAddress());
        $log->save();

      $user->endWriteLocking();
    $user->saveTransaction();

    return $this;
  }


  /**
   * @task email
   */
  public function removeEmail(
    PhabricatorUser $user,
    PhabricatorUserEmail $email) {

    $actor = $this->requireActor();

    if (!$user->getID()) {
      throw new Exception("User has not been created yet!");
    }
    if (!$email->getID()) {
      throw new Exception("Email has not been created yet!");
    }

    $user->openTransaction();
      $user->beginWriteLocking();

        $user->reload();
        $email->reload();

        if ($email->getIsPrimary()) {
          throw new Exception("Can't remove primary email!");
        }
        if ($email->getUserPHID() != $user->getPHID()) {
          throw new Exception("Email not owned by user!");
        }

        $email->delete();

        $log = PhabricatorUserLog::newLog(
          $actor,
          $user,
          PhabricatorUserLog::ACTION_EMAIL_REMOVE);
        $log->setOldValue($email->getAddress());
        $log->save();

      $user->endWriteLocking();
    $user->saveTransaction();

    return $this;
  }


  /**
   * @task email
   */
  public function changePrimaryEmail(
    PhabricatorUser $user,
    PhabricatorUserEmail $email) {
    $actor = $this->requireActor();

    if (!$user->getID()) {
      throw new Exception("User has not been created yet!");
    }
    if (!$email->getID()) {
      throw new Exception("Email has not been created yet!");
    }

    $user->openTransaction();
      $user->beginWriteLocking();

        $user->reload();
        $email->reload();

        if ($email->getUserPHID() != $user->getPHID()) {
          throw new Exception("User does not own email!");
        }

        if ($email->getIsPrimary()) {
          throw new Exception("Email is already primary!");
        }

        if (!$email->getIsVerified()) {
          throw new Exception("Email is not verified!");
        }

        $old_primary = $user->loadPrimaryEmail();
        if ($old_primary) {
          $old_primary->setIsPrimary(0);
          $old_primary->save();
        }

        $email->setIsPrimary(1);
        $email->save();

        $log = PhabricatorUserLog::newLog(
          $actor,
          $user,
          PhabricatorUserLog::ACTION_EMAIL_PRIMARY);
        $log->setOldValue($old_primary ? $old_primary->getAddress() : null);
        $log->setNewValue($email->getAddress());

        $log->save();

      $user->endWriteLocking();
    $user->saveTransaction();

    if ($old_primary) {
      $old_primary->sendOldPrimaryEmail($user, $email);
    }
    $email->sendNewPrimaryEmail($user);

    return $this;
  }


/* -(  Internals  )---------------------------------------------------------- */


  /**
   * @task internal
   */
  private function willAddEmail(PhabricatorUserEmail $email) {

    // Hard check before write to prevent creation of disallowed email
    // addresses. Normally, the application does checks and raises more
    // user friendly errors for us, but we omit the courtesy checks on some
    // pathways like administrative scripts for simplicity.

    if (!PhabricatorUserEmail::isAllowedAddress($email->getAddress())) {
      throw new Exception(PhabricatorUserEmail::describeAllowedAddresses());
    }
  }

}
