<?php

final class DiffusionGitStableCommitNameQuery
extends DiffusionStableCommitNameQuery {

  protected function executeQuery() {
    $repository = $this->getRepository();
    $branch = $this->getBranch();

    if ($repository->isWorkingCopyBare()) {
      list($stdout) = $repository->execxLocalCommand(
        'rev-parse --verify %s',
        $branch);
    } else {
      list($stdout) = $repository->execxLocalCommand(
        'rev-parse --verify %s/%s',
        DiffusionBranchInformation::DEFAULT_GIT_REMOTE,
        $branch);
    }

    $commit = trim($stdout);
    return substr($commit, 0, 16);
  }
}
