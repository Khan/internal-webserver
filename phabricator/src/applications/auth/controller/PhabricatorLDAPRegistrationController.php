<?php

final class PhabricatorLDAPRegistrationController
extends PhabricatorAuthController {
  private $ldapProvider;
  private $ldapInfo;

  public function setLDAPProvider($provider) {
    $this->ldapProvider = $provider;
    return $this;
  }

  public function getLDAProvider() {
    return $this->ldapProvider;
  }

  public function setLDAPInfo($info) {
    $this->ldapInfo = $info;
    return $this;
  }

  public function getLDAPInfo() {
    return $this->ldapInfo;
  }

  public function processRequest() {
    $provider = $this->getLDAProvider();
    $ldap_info = $this->getLDAPInfo();
    $request = $this->getRequest();

    $errors = array();
    $e_username = true;
    $e_email = true;
    $e_realname = true;

    $user = new PhabricatorUser();
    $user->setUsername($provider->retrieveUsername());
    $user->setRealname($provider->retrieveUserRealName());

    $new_email = $provider->retrieveUserEmail();

    if ($new_email) {
      // If the user's LDAP provider account has an email address but the
      // email address domain is not allowed by the Phabricator configuration,
      // we just pretend the provider did not supply an address.
      //
      // For instance, if the user uses LDAP Auth and their email address
      // is "joe@personal.com" but Phabricator is configured to require users
      // use "@company.com" addresses, we show a prompt below and tell the user
      // to provide their "@company.com" address. They can still use the LDAP
      // account to login, they just need to associate their account with an
      // allowed address.
      //
      // If the email address is fine, we just use it and don't prompt the user.
      if (!PhabricatorUserEmail::isAllowedAddress($new_email)) {
        $new_email = null;
      }
    }

    $show_email_input = ($new_email === null);

    if ($request->isFormPost()) {
      $user->setUsername($request->getStr('username'));
      $username = $user->getUsername();
      if (!strlen($user->getUsername())) {
        $e_username = pht('Required');
        $errors[] = pht('Username is required.');
      } else if (!PhabricatorUser::validateUsername($username)) {
        $e_username = pht('Invalid');
        $errors[] = PhabricatorUser::describeValidUsername();
      } else {
        $e_username = null;
      }

      if (!$new_email) {
        $new_email = trim($request->getStr('email'));
        if (!$new_email) {
          $e_email = pht('Required');
          $errors[] = pht('Email is required.');
        } else {
          $e_email = null;
        }
      }

      if ($new_email) {
        if (!PhabricatorUserEmail::isAllowedAddress($new_email)) {
          $e_email = pht('Invalid');
          $errors[] = PhabricatorUserEmail::describeAllowedAddresses();
        }
      }

      if (!strlen($user->getRealName())) {
        $user->setRealName($request->getStr('realname'));
        if (!strlen($user->getRealName())) {
          $e_realname = pht('Required');
          $errors[] = pht('Real name is required.');
        } else {
          $e_realname = null;
        }
      }

      if (!$errors) {
        try {
          // NOTE: We don't verify LDAP email addresses by default because
          // LDAP providers might associate email addresses with accounts that
          // haven't actually verified they own them. We could selectively
          // auto-verify some providers that we trust here, but the stakes for
          // verifying an email address are high because having a corporate
          // address at a company is sometimes the key to the castle.

          $email_obj = id(new PhabricatorUserEmail())
            ->setAddress($new_email)
            ->setIsVerified(0);

          id(new PhabricatorUserEditor())
            ->setActor($user)
            ->createNewUser($user, $email_obj);

          $ldap_info->setUserPHID($user->getPHID());
          $ldap_info->save();

          $session_key = $user->establishSession('web');
          $request->setCookie('phusr', $user->getUsername());
          $request->setCookie('phsid', $session_key);

          $email_obj->sendVerificationEmail($user);

          return id(new AphrontRedirectResponse())->setURI('/');
        } catch (AphrontQueryDuplicateKeyException $exception) {

          $same_username = id(new PhabricatorUser())->loadOneWhere(
            'userName = %s',
            $user->getUserName());

          $same_email = id(new PhabricatorUserEmail())->loadOneWhere(
            'address = %s',
            $new_email);

          if ($same_username) {
            $e_username = pht('Duplicate');
            $errors[] = pht('That username or email is not unique.');
          } else if ($same_email) {
            $e_email = pht('Duplicate');
            $errors[] = pht('That email is not unique.');
          } else {
            throw $exception;
          }
        }
      }
    }


    $error_view = null;
    if ($errors) {
      $error_view = new AphrontErrorView();
      $error_view->setTitle(pht('Registration Failed'));
      $error_view->setErrors($errors);
    }

    // Strip the URI down to the path, because otherwise we'll trigger
    // external CSRF protection (by having a protocol in the form "action")
    // and generate a form with no CSRF token.
    $action_uri = new PhutilURI('/ldap/login/');
    $action_path = $action_uri->getPath();

    $form = new AphrontFormView();
    $form
      ->setUser($request->getUser())
      ->setAction($action_path)
      ->appendChild(
        id(new AphrontFormTextControl())
        ->setLabel(pht('Username'))
        ->setName('username')
        ->setValue($user->getUsername())
        ->setError($e_username));

    $form->appendChild(
      id(new AphrontFormPasswordControl())
        ->setLabel(pht('Password'))
        ->setName('password'));

    if ($show_email_input) {
      $form->appendChild(
        id(new AphrontFormTextControl())
        ->setLabel(pht('Email'))
        ->setName('email')
        ->setValue($request->getStr('email'))
        ->setError($e_email));
    }

    if ($provider->retrieveUserRealName() === null) {
      $form->appendChild(
        id(new AphrontFormTextControl())
        ->setLabel(pht('Real Name'))
        ->setName('realname')
        ->setValue($request->getStr('realname'))
        ->setError($e_realname));
    }

    $form
      ->appendChild(
        id(new AphrontFormSubmitControl())
        ->setValue(pht('Create Account')));

    $panel = new AphrontPanelView();
    $panel->setHeader(pht('Create New Account'));
    $panel->setWidth(AphrontPanelView::WIDTH_FORM);
    $panel->appendChild($form);

    return $this->buildStandardPageResponse(
      array(
        $error_view,
        $panel,
      ),
      array(
        'title' => pht('Create New Account'),
      ));
  }

}
