<?php

final class PhabricatorMustVerifyEmailController
  extends PhabricatorAuthController {

  public function shouldRequireLogin() {
    return false;
  }

  public function shouldRequireEmailVerification() {
    // NOTE: We don't technically need this since PhabricatorController forces
    // us here in either case, but it's more consistent with intent.
    return false;
  }

  public function processRequest() {
    $request = $this->getRequest();
    $user = $request->getUser();

    $email = $user->loadPrimaryEmail();

    if ($user->getIsEmailVerified()) {
      return id(new AphrontRedirectResponse())->setURI('/');
    }

    $email_address = $email->getAddress();

    $sent = null;
    if ($request->isFormPost()) {
      $email->sendVerificationEmail($user);
      $sent = new AphrontErrorView();
      $sent->setSeverity(AphrontErrorView::SEVERITY_NOTICE);
      $sent->setTitle(pht('Email Sent'));
      $sent->appendChild(
        pht(
          'Another verification email was sent to %s.',
          phutil_tag('strong', array(), $email_address)));
    }

    $must_verify = pht(
      'You must verify your email address to login. You should have a '.
      'new email message from Phabricator with verification instructions '.
      'in your inbox (%s).',
      phutil_tag('strong', array(), $email_address));

    $send_again = pht(
      'If you did not receive an email, you can click the button below '.
      'to try sending another one.');

    $dialog = id(new AphrontDialogView())
      ->setUser($user)
      ->setTitle(pht('Check Your Email'))
      ->appendParagraph($must_verify)
      ->appendParagraph($send_again)
      ->addSubmitButton(pht('Send Another Email'));

    return $this->buildApplicationPage(
      array(
        $sent,
        $dialog,
      ),
      array(
        'title' => pht('Must Verify Email'),
        'device' => true
      ));
  }

}
