<?php

final class DiffusionMercurialRawDiffQuery extends DiffusionRawDiffQuery {

  protected function executeQuery() {
    return $this->executeRawDiffCommand();
  }


  protected function executeRawDiffCommand() {
    $drequest = $this->getRequest();
    $repository = $drequest->getRepository();

    $commit = $drequest->getCommit();

    // If there's no path, get the entire raw diff.
    $path = nonempty($drequest->getPath(), '.');

    $against = $this->getAgainstCommit();
    if ($against === null) {
      // If `$commit` has no parents (usually because it's the first commit
      // in the repository), we want to diff against `null`. This revset will
      // do that for us automatically.
      $against = '('.$commit.'^ or null)';
    }

    $future = $repository->getLocalCommandFuture(
      'diff -U %d --git --rev %s --rev %s -- %s',
      $this->getLinesOfContext(),
      $against,
      $commit,
      $path);

    if ($this->getTimeout()) {
      $future->setTimeout($this->getTimeout());
    }

    list($raw_diff) = $future->resolvex();

    return $raw_diff;
  }

}
