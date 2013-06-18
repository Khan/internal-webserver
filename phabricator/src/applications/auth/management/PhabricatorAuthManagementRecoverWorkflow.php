<?php

final class PhabricatorAuthManagementRecoverWorkflow
  extends PhabricatorAuthManagementWorkflow {

  protected function didConstruct() {
    $this
      ->setName('recover')
      ->setExamples('**recover** __username__')
      ->setSynopsis(
        'Recover access to an administrative account if you have locked '.
        'yourself out of Phabricator.')
      ->setArguments(
        array(
          'username' => array(
            'name' => 'username',
            'wildcard' => true,
          ),
        ));
  }

  public function execute(PhutilArgumentParser $args) {

    $can_recover = id(new PhabricatorPeopleQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withIsAdmin(true)
      ->execute();
    if (!$can_recover) {
      throw new PhutilArgumentUsageException(
        pht(
          'This Phabricator installation has no recoverable administrator '.
          'accounts. You can use `bin/accountadmin` to create a new '.
          'administrator account or make an existing user an administrator.'));
    }
    $can_recover = mpull($can_recover, 'getUsername');
    sort($can_recover);
    $can_recover = implode(', ', $can_recover);

    $usernames = $args->getArg('username');
    if (!$usernames) {
      throw new PhutilArgumentUsageException(
        pht('You must specify the username of the account to recover.'));
    } else if (count($usernames) > 1) {
      throw new PhutilArgumentUsageException(
        pht('You can only recover the username for one account.'));
    }

    $username = head($usernames);

    $user = id(new PhabricatorPeopleQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withUsernames(array($username))
      ->executeOne();

    if (!$user) {
      throw new PhutilArgumentUsageException(
        pht(
          'No such user "%s". Recoverable administrator accounts are: %s.',
          $username,
          $can_recover));
    }

    if (!$user->getIsAdmin()) {
      throw new PhutilArgumentUsageException(
        pht(
          'You can only recover administrator accounts, but %s is not an '.
          'administrator. Recoverable administrator accounts are: %s.',
          $username,
          $can_recover));
    }

    $console = PhutilConsole::getConsole();
    $console->writeOut(
      pht(
        'Use this link to recover access to the "%s" account:',
        $username));
    $console->writeOut("\n\n");
    $console->writeOut("    %s", $user->getEmailLoginURI());
    $console->writeOut("\n\n");
    $console->writeOut(
      pht(
        'After logging in, you can use the "Auth" application to add or '.
        'restore authentication providers and allow normal logins to '.
        'succeed.')."\n");

    return 0;
  }

}
