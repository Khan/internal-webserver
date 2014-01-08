<?php

final class PhabricatorRepositoryManagementLookupUsersWorkflow
  extends PhabricatorRepositoryManagementWorkflow {

  public function didConstruct() {
    $this
      ->setName('lookup-users')
      ->setExamples('**lookup-users** __commit__ ...')
      ->setSynopsis('Resolve user accounts for users attached to __commit__.')
      ->setArguments(
        array(
          array(
            'name'        => 'commits',
            'wildcard'    => true,
          ),
        ));
  }

  public function execute(PhutilArgumentParser $args) {
    $commits = $this->loadCommits($args, 'commits');
    if (!$commits) {
      throw new PhutilArgumentUsageException(
        "Specify one or more commits to resolve users for.");
    }

    $console = PhutilConsole::getConsole();
    foreach ($commits as $commit) {
      $repo = $commit->getRepository();
      $name =  $repo->formatCommitName($commit->getCommitIdentifier());

      $console->writeOut(
        "%s\n",
        pht("Examining commit %s...", $name));

      $ref = id(new DiffusionLowLevelCommitQuery())
        ->setRepository($repo)
        ->withIdentifier($commit->getCommitIdentifier())
        ->execute();

      $author = $ref->getAuthor();
      $console->writeOut(
        "%s\n",
        pht('Raw author string: %s', coalesce($author, 'null')));

      if ($author !== null) {
        $handle = $this->resolveUser($commit, $author);
        if ($handle) {
          $console->writeOut(
            "%s\n",
            pht('Phabricator user: %s', $handle->getFullName()));
        } else {
          $console->writeOut(
            "%s\n",
            pht('Unable to resolve a corresponding Phabricator user.'));
        }
      }

      $committer = $ref->getCommitter();
      $console->writeOut(
        "%s\n",
        pht('Raw committer string: %s', coalesce($committer, 'null')));

      if ($committer !== null) {
        $handle = $this->resolveUser($commit, $committer);
        if ($handle) {
          $console->writeOut(
            "%s\n",
            pht('Phabricator user: %s', $handle->getFullName()));
        } else {
          $console->writeOut(
            "%s\n",
            pht('Unable to resolve a corresponding Phabricator user.'));
        }
      }
    }

    return 0;
  }

  private function resolveUser(PhabricatorRepositoryCommit $commit, $name) {
    $phid = id(new DiffusionResolveUserQuery())
      ->withCommit($commit)
      ->withName($name)
      ->execute();

    if (!$phid) {
      return null;
    }

    return id(new PhabricatorHandleQuery())
      ->setViewer($this->getViewer())
      ->withPHIDs(array($phid))
      ->executeOne();
  }

}
