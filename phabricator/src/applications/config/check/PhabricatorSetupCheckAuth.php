<?php

final class PhabricatorSetupCheckAuth extends PhabricatorSetupCheck {

  protected function executeChecks() {
    // NOTE: We're not actually building these providers. Building providers
    // can require additional configuration to be present (e.g., to build
    // redirect and login URIs using `phabricator.base-uri`) and it won't
    // necessarily be available when running setup checks.

    // Since this check is only meant as a hint to new administrators about
    // steps they should take, we don't need to be thorough about checking
    // that providers are enabled, available, correctly configured, etc. As
    // long as they've created some kind of provider in the auth app before,
    // they know that it exists and don't need the hint to go check it out.

    $configs = id(new PhabricatorAuthProviderConfigQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->execute();

    if (!$configs) {
      $message = pht(
        'You have not configured any authentication providers yet. You '.
        'should add a provider (like username/password, LDAP, or GitHub '.
        'OAuth) so users can register and log in. You can add and configure '.
        'providers %s.',
        phutil_tag(
          'a',
          array(
            'href' => '/auth/',
          ),
          pht('using the "Auth" application')));

      $this
        ->newIssue('auth.noproviders')
        ->setShortName(pht('No Auth Providers'))
        ->setName(pht('No Authentication Providers Configured'))
        ->setMessage($message);
    }
  }
}
