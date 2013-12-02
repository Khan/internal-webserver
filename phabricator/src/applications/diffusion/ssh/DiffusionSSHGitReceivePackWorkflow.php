<?php

final class DiffusionSSHGitReceivePackWorkflow
  extends DiffusionSSHGitWorkflow {

  public function didConstruct() {
    $this->setName('git-receive-pack');
    $this->setArguments(
      array(
        array(
          'name'      => 'dir',
          'wildcard'  => true,
        ),
      ));
  }

  protected function executeRepositoryOperations() {
    $args = $this->getArgs();
    $path = head($args->getArg('dir'));
    $repository = $this->loadRepository($path);

    // This is a write, and must have write access.
    $this->requireWriteAccess();

    $command = csprintf('git-receive-pack %s', $repository->getLocalPath());
    $command = PhabricatorDaemon::sudoCommandAsDaemonUser($command);

    $future = id(new ExecFuture('%C', $command))
      ->setEnv($this->getEnvironment());

    $err = $this->newPassthruCommand()
      ->setIOChannel($this->getIOChannel())
      ->setCommandChannelFromExecFuture($future)
      ->execute();

    if (!$err) {
      $repository->writeStatusMessage(
        PhabricatorRepositoryStatusMessage::TYPE_NEEDS_UPDATE,
        PhabricatorRepositoryStatusMessage::CODE_OKAY);
      $this->waitForGitClient();
    }

    return $err;
  }

}
