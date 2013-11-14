<?php

final class PhabricatorAuthRegisterController
  extends PhabricatorAuthController {

  private $accountKey;

  public function shouldRequireLogin() {
    return false;
  }

  public function willProcessRequest(array $data) {
    $this->accountKey = idx($data, 'akey');
  }

  public function processRequest() {
    $request = $this->getRequest();

    if ($request->getUser()->isLoggedIn()) {
      return $this->renderError(pht('You are already logged in.'));
    }

    $is_setup = false;
    if (strlen($this->accountKey)) {
      $result = $this->loadAccountForRegistrationOrLinking($this->accountKey);
      list($account, $provider, $response) = $result;
      $is_default = false;
    } else if ($this->isFirstTimeSetup()) {
      list($account, $provider, $response) = $this->loadSetupAccount();
      $is_default = true;
      $is_setup = true;
    } else {
      list($account, $provider, $response) = $this->loadDefaultAccount();
      $is_default = true;
    }

    if ($response) {
      return $response;
    }

    if (!$provider->shouldAllowRegistration()) {

      // TODO: This is a routine error if you click "Login" on an external
      // auth source which doesn't allow registration. The error should be
      // more tailored.

      return $this->renderError(
        pht(
          'The account you are attempting to register with uses an '.
          'authentication provider ("%s") which does not allow registration. '.
          'An administrator may have recently disabled registration with this '.
          'provider.',
          $provider->getProviderName()));
    }

    $user = new PhabricatorUser();

    $default_username = $account->getUsername();
    $default_realname = $account->getRealName();
    $default_email = $account->getEmail();
    if ($default_email) {
      // If the account source provided an email, but it's not allowed by
      // the configuration, roadblock the user. Previously, we let the user
      // pick a valid email address instead, but this does not align well with
      // user expectation and it's not clear the cases it enables are valuable.
      // See discussion in T3472.
      if (!PhabricatorUserEmail::isAllowedAddress($default_email)) {
        return $this->renderError(
          array(
            pht(
              'The account you are attempting to register with has an invalid '.
              'email address (%s). This Phabricator install only allows '.
              'registration with specific email addresses:',
              $default_email),
            phutil_tag('br'),
            phutil_tag('br'),
            PhabricatorUserEmail::describeAllowedAddresses(),
          ));
      }

      // If the account source provided an email, but another account already
      // has that email, just pretend we didn't get an email.

      // TODO: See T3340.
      // TODO: See T3472.

      if ($default_email) {
        $same_email = id(new PhabricatorUserEmail())->loadOneWhere(
          'address = %s',
          $default_email);
        if ($same_email) {
          $default_email = null;
        }
      }
    }

    $profile = id(new PhabricatorRegistrationProfile())
      ->setDefaultUsername($default_username)
      ->setDefaultEmail($default_email)
      ->setDefaultRealName($default_realname)
      ->setCanEditUsername(true)
      ->setCanEditEmail(($default_email === null))
      ->setCanEditRealName(true)
      ->setShouldVerifyEmail(false);

    $event_type = PhabricatorEventType::TYPE_AUTH_WILLREGISTERUSER;
    $event_data = array(
      'account' => $account,
      'profile' => $profile,
    );

    $event = id(new PhabricatorEvent($event_type, $event_data))
      ->setUser($user);
    PhutilEventEngine::dispatchEvent($event);

    $default_username = $profile->getDefaultUsername();
    $default_email = $profile->getDefaultEmail();
    $default_realname = $profile->getDefaultRealName();

    $can_edit_username = $profile->getCanEditUsername();
    $can_edit_email = $profile->getCanEditEmail();
    $can_edit_realname = $profile->getCanEditRealName();

    $must_set_password = $provider->shouldRequireRegistrationPassword();

    $can_edit_anything = $profile->getCanEditAnything() || $must_set_password;
    $force_verify = $profile->getShouldVerifyEmail();

    $value_username = $default_username;
    $value_realname = $default_realname;
    $value_email = $default_email;
    $value_password = null;

    $errors = array();

    $e_username = strlen($value_username) ? null : true;
    $e_realname = strlen($value_realname) ? null : true;
    $e_email = strlen($value_email) ? null : true;
    $e_password = true;
    $e_captcha = true;

    $min_len = PhabricatorEnv::getEnvConfig('account.minimum-password-length');
    $min_len = (int)$min_len;

    if ($request->isFormPost() || !$can_edit_anything) {
      $unguarded = AphrontWriteGuard::beginScopedUnguardedWrites();

      if ($must_set_password) {
        $e_captcha = pht('Again');

        $captcha_ok = AphrontFormRecaptchaControl::processCaptcha($request);
        if (!$captcha_ok) {
          $errors[] = pht("Captcha response is incorrect, try again.");
          $e_captcha = pht('Invalid');
        }
      }

      if ($can_edit_username) {
        $value_username = $request->getStr('username');
        if (!strlen($value_username)) {
          $e_username = pht('Required');
          $errors[] = pht('Username is required.');
        } else if (!PhabricatorUser::validateUsername($value_username)) {
          $e_username = pht('Invalid');
          $errors[] = PhabricatorUser::describeValidUsername();
        } else {
          $e_username = null;
        }
      }

      if ($must_set_password) {
        $value_password = $request->getStr('password');
        $value_confirm = $request->getStr('confirm');
        if (!strlen($value_password)) {
          $e_password = pht('Required');
          $errors[] = pht('You must choose a password.');
        } else if ($value_password !== $value_confirm) {
          $e_password = pht('No Match');
          $errors[] = pht('Password and confirmation must match.');
        } else if (strlen($value_password) < $min_len) {
          $e_password = pht('Too Short');
          $errors[] = pht(
            'Password is too short (must be at least %d characters long).',
            $min_len);
        } else {
          $e_password = null;
        }
      }

      if ($can_edit_email) {
        $value_email = $request->getStr('email');
        if (!strlen($value_email)) {
          $e_email = pht('Required');
          $errors[] = pht('Email is required.');
        } else if (!PhabricatorUserEmail::isAllowedAddress($value_email)) {
          $e_email = pht('Invalid');
          $errors[] = PhabricatorUserEmail::describeAllowedAddresses();
        } else {
          $e_email = null;
        }
      }

      if ($can_edit_realname) {
        $value_realname = $request->getStr('realName');
        if (!strlen($value_realname)) {
          $e_realname = pht('Required');
          $errors[] = pht('Real name is required.');
        } else {
          $e_realname = null;
        }
      }

      if (!$errors) {
        $image = $this->loadProfilePicture($account);
        if ($image) {
          $user->setProfileImagePHID($image->getPHID());
        }

        try {
          if ($force_verify) {
            $verify_email = true;
          } else {
            $verify_email =
              ($account->getEmailVerified()) &&
              ($value_email === $default_email);
          }

          $email_obj = id(new PhabricatorUserEmail())
            ->setAddress($value_email)
            ->setIsVerified((int)$verify_email);

          $user->setUsername($value_username);
          $user->setRealname($value_realname);

          if ($is_setup) {
            $must_approve = false;
          } else {
            $must_approve = PhabricatorEnv::getEnvConfig(
              'auth.require-approval');
          }

          if ($must_approve) {
            $user->setIsApproved(0);
          } else {
            $user->setIsApproved(1);
          }

          $user->openTransaction();

            $editor = id(new PhabricatorUserEditor())
              ->setActor($user);

            $editor->createNewUser($user, $email_obj);
            if ($must_set_password) {
              $envelope = new PhutilOpaqueEnvelope($value_password);
              $editor->changePassword($user, $envelope);
            }

            if ($is_setup) {
              $editor->makeAdminUser($user, true);
            }

            $account->setUserPHID($user->getPHID());
            $provider->willRegisterAccount($account);
            $account->save();

          $user->saveTransaction();

          if (!$email_obj->getIsVerified()) {
            $email_obj->sendVerificationEmail($user);
          }

          if ($must_approve) {
            $this->sendWaitingForApprovalEmail($user);
          }

          return $this->loginUser($user);
        } catch (AphrontQueryDuplicateKeyException $exception) {
          $same_username = id(new PhabricatorUser())->loadOneWhere(
            'userName = %s',
            $user->getUserName());

          $same_email = id(new PhabricatorUserEmail())->loadOneWhere(
            'address = %s',
            $value_email);

          if ($same_username) {
            $e_username = pht('Duplicate');
            $errors[] = pht('Another user already has that username.');
          }

          if ($same_email) {
            // TODO: See T3340.
            $e_email = pht('Duplicate');
            $errors[] = pht('Another user already has that email.');
          }

          if (!$same_username && !$same_email) {
            throw $exception;
          }
        }
      }

      unset($unguarded);
    }

    $error_view = null;
    if ($errors) {
      $error_view = new AphrontErrorView();
      $error_view->setTitle(pht('Registration Failed'));
      $error_view->setErrors($errors);
    }

    $form = id(new AphrontFormView())
      ->setUser($request->getUser());

    if (!$is_default) {
      $form->appendChild(
        id(new AphrontFormMarkupControl())
          ->setLabel(pht('External Account'))
          ->setValue(
            id(new PhabricatorAuthAccountView())
              ->setUser($request->getUser())
              ->setExternalAccount($account)
              ->setAuthProvider($provider)));
    }


    if ($can_edit_username) {
      $form->appendChild(
        id(new AphrontFormTextControl())
          ->setLabel(pht('Phabricator Username'))
          ->setName('username')
          ->setValue($value_username)
          ->setError($e_username));
    } else {
      $form->appendChild(
        id(new AphrontFormMarkupControl())
          ->setLabel(pht('Phabricator Username'))
          ->setValue($value_username)
          ->setError($e_username));
    }

    if ($must_set_password) {
      $form->appendChild(
        id(new AphrontFormPasswordControl())
          ->setLabel(pht('Password'))
          ->setName('password')
          ->setError($e_password)
          ->setCaption(
            $min_len
              ? pht('Minimum length of %d characters.', $min_len)
              : null));
      $form->appendChild(
        id(new AphrontFormPasswordControl())
          ->setLabel(pht('Confirm Password'))
          ->setName('confirm')
          ->setError($e_password));
    }

    if ($can_edit_email) {
      $form->appendChild(
        id(new AphrontFormTextControl())
          ->setLabel(pht('Email'))
          ->setName('email')
          ->setValue($value_email)
          ->setCaption(PhabricatorUserEmail::describeAllowedAddresses())
          ->setError($e_email));
    }

    if ($can_edit_realname) {
      $form->appendChild(
        id(new AphrontFormTextControl())
          ->setLabel(pht('Real Name'))
          ->setName('realName')
          ->setValue($value_realname)
          ->setError($e_realname));
    }

    if ($must_set_password) {
      $form->appendChild(
        id(new AphrontFormRecaptchaControl())
          ->setLabel(pht('Captcha'))
          ->setError($e_captcha));
    }

    $submit = id(new AphrontFormSubmitControl());

    if ($is_setup) {
      $submit
        ->setValue(pht('Create Admin Account'));
    } else {
      $submit
        ->addCancelButton($this->getApplicationURI('start/'))
        ->setValue(pht('Register Phabricator Account'));
    }


    $form->appendChild($submit);

    $crumbs = $this->buildApplicationCrumbs();

    if ($is_setup) {
      $crumbs->addCrumb(
        id(new PhabricatorCrumbView())
          ->setName(pht('Setup Admin Account')));
        $title = pht('Welcome to Phabricator');
    } else {
      $crumbs->addCrumb(
        id(new PhabricatorCrumbView())
          ->setName(pht('Register')));
      $crumbs->addCrumb(
        id(new PhabricatorCrumbView())
          ->setName($provider->getProviderName()));
        $title = pht('Phabricator Registration');
    }

    $welcome_view = null;
    if ($is_setup) {
      $welcome_view = id(new AphrontErrorView())
        ->setSeverity(AphrontErrorView::SEVERITY_NOTICE)
        ->setTitle(pht('Welcome to Phabricator'))
        ->appendChild(
          pht(
            'Installation is complete. Register your administrator account '.
            'below to log in. You will be able to configure options and add '.
            'other authentication mechanisms (like LDAP or OAuth) later on.'));
    }

    $object_box = id(new PHUIObjectBoxView())
      ->setHeaderText($title)
      ->setForm($form)
      ->setFormError($error_view);

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $welcome_view,
        $object_box,
      ),
      array(
        'title' => $title,
        'device' => true,
      ));
  }

  private function loadDefaultAccount() {
    $providers = PhabricatorAuthProvider::getAllEnabledProviders();
    $account = null;
    $provider = null;
    $response = null;

    foreach ($providers as $key => $candidate_provider) {
      if (!$candidate_provider->shouldAllowRegistration()) {
        unset($providers[$key]);
        continue;
      }
      if (!$candidate_provider->isDefaultRegistrationProvider()) {
        unset($providers[$key]);
      }
    }

    if (!$providers) {
      $response = $this->renderError(
        pht(
          "There are no configured default registration providers."));
      return array($account, $provider, $response);
    } else if (count($providers) > 1) {
      $response = $this->renderError(
        pht(
          "There are too many configured default registration providers."));
      return array($account, $provider, $response);
    }

    $provider = head($providers);
    $account = $provider->getDefaultExternalAccount();

    return array($account, $provider, $response);
  }

  private function loadSetupAccount() {
    $provider = new PhabricatorAuthProviderPassword();
    $provider->attachProviderConfig(
      id(new PhabricatorAuthProviderConfig())
        ->setShouldAllowRegistration(1)
        ->setShouldAllowLogin(1)
        ->setIsEnabled(true));

    $account = $provider->getDefaultExternalAccount();
    $response = null;
    return array($account, $provider, $response);
  }

  private function loadProfilePicture(PhabricatorExternalAccount $account) {
    $phid = $account->getProfileImagePHID();
    if (!$phid) {
      return null;
    }

    // NOTE: Use of omnipotent user is okay here because the registering user
    // can not control the field value, and we can't use their user object to
    // do meaningful policy checks anyway since they have not registered yet.
    // Reaching this means the user holds the account secret key and the
    // registration secret key, and thus has permission to view the image.

    $file = id(new PhabricatorFileQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs(array($phid))
      ->executeOne();
    if (!$file) {
      return null;
    }

    try {
      $xformer = new PhabricatorImageTransformer();
      return $xformer->executeProfileTransform(
        $file,
        $width = 50,
        $min_height = 50,
        $max_height = 50);
    } catch (Exception $ex) {
      phlog($ex);
      return null;
    }
  }

  protected function renderError($message) {
    return $this->renderErrorPage(
      pht('Registration Failed'),
      array($message));
  }

  private function sendWaitingForApprovalEmail(PhabricatorUser $user) {
    $title = '[Phabricator] '.pht(
      'New User "%s" Awaiting Approval',
      $user->getUsername());

    $body = new PhabricatorMetaMTAMailBody();

    $body->addRawSection(
      pht(
        'Newly registered user "%s" is awaiting account approval by an '.
        'administrator.',
        $user->getUsername()));

    $body->addTextSection(
      pht('APPROVAL QUEUE'),
      PhabricatorEnv::getProductionURI(
        '/people/query/approval/'));

    $body->addTextSection(
      pht('DISABLE APPROVAL QUEUE'),
      PhabricatorEnv::getProductionURI(
        '/config/edit/auth.require-approval/'));

    $admins = id(new PhabricatorPeopleQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withIsAdmin(true)
      ->execute();

    if (!$admins) {
      return;
    }

    $mail = id(new PhabricatorMetaMTAMail())
      ->addTos(mpull($admins, 'getPHID'))
      ->setSubject($title)
      ->setBody($body->render())
      ->saveAndSend();
  }

}
