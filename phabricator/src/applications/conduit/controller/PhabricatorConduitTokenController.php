<?php

/**
 * @group conduit
 */
final class PhabricatorConduitTokenController
  extends PhabricatorConduitController {

  public function processRequest() {

    $user = $this->getRequest()->getUser();

    // Ideally we'd like to verify this, but it's fine to leave it unguarded
    // for now and verifying it would need some Ajax junk or for the user to
    // click a button or similar.
    $unguarded = AphrontWriteGuard::beginScopedUnguardedWrites();

    $old_token = id(new PhabricatorConduitCertificateToken())
      ->loadOneWhere(
        'userPHID = %s',
        $user->getPHID());
    if ($old_token) {
      $old_token->delete();
    }

    $token = id(new PhabricatorConduitCertificateToken())
      ->setUserPHID($user->getPHID())
      ->setToken(Filesystem::readRandomCharacters(40))
      ->save();

    unset($unguarded);

    $pre_instructions = pht(
      'Copy and paste this token into the prompt given to you by '.
      '`arc install-certificate`');

    $post_instructions = pht(
      'After you copy and paste this token, `arc` will complete '.
      'the certificate install process for you.');

    $form = id(new AphrontFormView())
      ->setUser($user)
      ->appendRemarkupInstructions($pre_instructions)
      ->appendChild(
        id(new AphrontFormTextAreaControl())
          ->setLabel(pht('Token'))
          ->setHeight(AphrontFormTextAreaControl::HEIGHT_VERY_SHORT)
          ->setValue($token->getToken()))
      ->appendRemarkupInstructions($post_instructions);

    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addCrumb(
      id(new PhabricatorCrumbView())
        ->setName(pht('Install Certificate')));

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $form,
      ),
      array(
        'title' => pht('Certificate Install Token'),
        'device' => true,
      ));
  }
}
