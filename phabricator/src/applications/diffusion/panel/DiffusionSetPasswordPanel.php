<?php

final class DiffusionSetPasswordPanel extends PhabricatorSettingsPanel {

  public function getPanelKey() {
    return 'vcspassword';
  }

  public function getPanelName() {
    return pht('VCS Password');
  }

  public function getPanelGroup() {
    return pht('Authentication');
  }

  public function isEnabled() {
    return PhabricatorEnv::getEnvConfig('diffusion.allow-http-auth');
  }

  public function processRequest(AphrontRequest $request) {
    $user = $request->getUser();

    $vcspassword = id(new PhabricatorRepositoryVCSPassword())
      ->loadOneWhere(
        'userPHID = %s',
        $user->getPHID());
    if (!$vcspassword) {
      $vcspassword = id(new PhabricatorRepositoryVCSPassword());
      $vcspassword->setUserPHID($user->getPHID());
    }

    $panel_uri = $this->getPanelURI('?saved=true');

    $errors = array();

    $e_password = true;
    $e_confirm = true;

    if ($request->isFormPost()) {
      if ($request->getBool('remove')) {
        if ($vcspassword->getID()) {
          $vcspassword->delete();
          return id(new AphrontRedirectResponse())->setURI($panel_uri);
        }
      }

      $new_password = $request->getStr('password');
      $confirm = $request->getStr('confirm');
      if (!strlen($new_password)) {
        $e_password = pht('Required');
        $errors[] = pht('Password is required.');
      } else {
        $e_password = null;
      }

      if (!strlen($confirm)) {
        $e_confirm = pht('Required');
        $errors[] = pht('You must confirm the new password.');
      } else {
        $e_confirm = null;
      }

      if (!$errors) {
        $envelope = new PhutilOpaqueEnvelope($new_password);

        if ($new_password !== $confirm) {
          $e_password = pht('Does Not Match');
          $e_confirm = pht('Does Not Match');
          $errors[] = pht('Password and confirmation do not match.');
        } else if ($user->comparePassword($envelope)) {
          $e_password = pht('Not Unique');
          $e_confirm = pht('Not Unique');
          $errors[] = pht(
            'This password is the same as another password associated '.
            'with your account. You must use a unique password for '.
            'VCS access.');
        } else if (
          PhabricatorCommonPasswords::isCommonPassword($new_password)) {
          $e_password = pht('Very Weak');
          $e_confirm = pht('Very Weak');
          $errors[] = pht(
            'This password is extremely weak: it is one of the most common '.
            'passwords in use. Choose a stronger password.');
        }


        if (!$errors) {
          $vcspassword->setPassword($envelope, $user);
          $vcspassword->save();

          return id(new AphrontRedirectResponse())->setURI($panel_uri);
        }
      }
    }

    $title = pht('Set VCS Password');

    $form = id(new AphrontFormView())
      ->setUser($user)
      ->appendRemarkupInstructions(
        pht(
          'To access repositories hosted by Phabricator over HTTP, you must '.
          'set a version control password. This password should be unique.'.
          "\n\n".
          "This password applies to all repositories available over ".
          "HTTP."));

    if ($vcspassword->getID()) {
      $form
        ->appendChild(
          id(new AphrontFormPasswordControl())
            ->setLabel(pht('Current Password'))
            ->setDisabled(true)
            ->setValue('********************'));
    } else {
      $form
        ->appendChild(
          id(new AphrontFormMarkupControl())
            ->setLabel(pht('Current Password'))
            ->setValue(phutil_tag('em', array(), pht('No Password Set'))));
    }

    $form
      ->appendChild(
        id(new AphrontFormPasswordControl())
          ->setName('password')
          ->setLabel(pht('New VCS Password'))
          ->setError($e_password))
      ->appendChild(
        id(new AphrontFormPasswordControl())
          ->setName('confirm')
          ->setLabel(pht('Confirm VCS Password'))
          ->setError($e_confirm))
      ->appendChild(
        id(new AphrontFormSubmitControl())
          ->setValue(pht('Change Password')));


    if (!$vcspassword->getID()) {
      $is_serious = PhabricatorEnv::getEnvConfig(
        'phabricator.serious-business');

      $suggest = Filesystem::readRandomBytes(128);
      $suggest = preg_replace('([^A-Za-z0-9/!().,;{}^&*%~])', '', $suggest);
      $suggest = substr($suggest, 0, 20);

      if ($is_serious) {
        $form->appendRemarkupInstructions(
          pht(
            'Having trouble coming up with a good password? Try this randomly '.
            'generated one, made by a computer:'.
            "\n\n".
            "`%s`",
            $suggest));
      } else {
        $form->appendRemarkupInstructions(
          pht(
            'Having trouble coming up with a good password? Try this '.
            'artisinal password, hand made in small batches by our expert '.
            'craftspeople: '.
            "\n\n".
            "`%s`",
            $suggest));
      }
    }

    $hash_envelope = new PhutilOpaqueEnvelope($vcspassword->getPasswordHash());

    $form->appendChild(
      id(new AphrontFormStaticControl())
        ->setLabel(pht('Current Algorithm'))
        ->setValue(
          PhabricatorPasswordHasher::getCurrentAlgorithmName($hash_envelope)));

    $form->appendChild(
      id(new AphrontFormStaticControl())
        ->setLabel(pht('Best Available Algorithm'))
        ->setValue(PhabricatorPasswordHasher::getBestAlgorithmName()));

    if (strlen($hash_envelope->openEnvelope())) {
      if (PhabricatorPasswordHasher::canUpgradeHash($hash_envelope)) {
        $errors[] = pht(
          'The strength of your stored VCS password hash can be upgraded. '.
          'To upgrade, either: use the password to authenticate with a '.
          'repository; or change your password.');
      }
    }

    $object_box = id(new PHUIObjectBoxView())
      ->setHeaderText($title)
      ->setForm($form)
      ->setFormErrors($errors);

    $remove_form = id(new AphrontFormView())
      ->setUser($user);

    if ($vcspassword->getID()) {
      $remove_form
        ->addHiddenInput('remove', true)
        ->appendRemarkupInstructions(
          pht(
            'You can remove your VCS password, which will prevent your '.
            'account from accessing repositories.'))
        ->appendChild(
          id(new AphrontFormSubmitControl())
            ->setValue(pht('Remove Password')));
    } else {
      $remove_form->appendRemarkupInstructions(
        pht(
          'You do not currently have a VCS password set. If you set one, you '.
          'can remove it here later.'));
    }

    $remove_box = id(new PHUIObjectBoxView())
      ->setHeaderText(pht('Remove VCS Password'))
      ->setForm($remove_form);

    $saved = null;
    if ($request->getBool('saved')) {
      $saved = id(new AphrontErrorView())
        ->setSeverity(AphrontErrorView::SEVERITY_NOTICE)
        ->setTitle(pht('Password Updated'))
        ->appendChild(pht('Your VCS password has been updated.'));
    }

    return array(
      $saved,
      $object_box,
      $remove_box,
    );
  }

}
