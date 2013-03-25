<?php

/**
 * Displays user's Git branches or Mercurial bookmarks
 *
 * @group workflow
 * @concrete-extensible
 */
class ArcanistFeatureWorkflow extends ArcanistBaseWorkflow {

  private $branches;

  public function getWorkflowName() {
    return 'feature';
  }

  public function getCommandSynopses() {
    return phutil_console_format(<<<EOTEXT
      **feature** [__options__]
      **feature** __name__ [__start__]
EOTEXT
      );
  }

  public function getCommandHelp() {
    return phutil_console_format(<<<EOTEXT
          Supports: git, hg
          A wrapper on 'git branch' or 'hg bookmark'.

          Without __name__, it lists the available branches and their revision
          status.

          With __name__, it creates or checks out a branch. If the branch
          __name__ doesn't exist and is in format D123 then the branch of
          revision D123 is checked out. Use __start__ to specify where the new
          branch will start. Use 'arc.feature.start.default' to set the default
          feature start location.
EOTEXT
      );
  }

  public function requiresConduit() {
    return true;
  }

  public function requiresRepositoryAPI() {
    return true;
  }

  public function requiresAuthentication() {
    return !$this->getArgument('names');
  }


  public function getArguments() {
    return array(
      'view-all' => array(
        'help' => 'Include closed and abandoned revisions.',
      ),
      'by-status' => array(
        'help' => 'Sort branches by status instead of time.',
      ),
      '*' => 'names',
    );
  }

  public function run() {
    $repository_api = $this->getRepositoryAPI();
    if (!($repository_api instanceof ArcanistGitAPI) &&
        !($repository_api instanceof ArcanistMercurialAPI)) {
      throw new ArcanistUsageException(
        'arc feature is only supported under Git and Mercurial.');
    }

    $names = $this->getArgument('names');
    if ($names) {
      if (count($names) > 2) {
        throw new ArcanistUsageException("Specify only one branch.");
      }
      return $this->checkoutBranch($names);
    }

    $branches = $repository_api->getAllBranches();
    if (!$branches) {
      throw new ArcanistUsageException('No branches in this working copy.');
    }

    $branches = $this->loadCommitInfo($branches);

    $revisions = $this->loadRevisions($branches);

    $this->printBranches($branches, $revisions);

    return 0;
  }

  private function checkoutBranch(array $names) {
    $api = $this->getRepositoryAPI();

    if ($api instanceof ArcanistMercurialAPI) {
      $command = 'update %s';
    } else {
      $command = 'checkout %s';
    }

    $err = 1;

    $name = $names[0];
    if (isset($names[1])) {
      $start = $names[1];
    } else {
      $start = $this->getWorkingCopy()->getConfigFromAnySource(
        'arc.feature.start.default');
    }

    $branches = $api->getAllBranches();
    if (in_array($name, ipull($branches, 'name'))) {
      list($err, $stdout, $stderr) = $api->execManualLocal(
        $command,
        $name);
    }

    if ($err) {
      $match = null;
      if (preg_match('/^D(\d+)$/', $name, $match)) {
        try {
          $diff = $this->getConduit()->callMethodSynchronous(
            'differential.getdiff',
            array(
              'revision_id' => $match[1],
            ));

          if ($diff['branch'] != '') {
            $name = $diff['branch'];
            list($err, $stdout, $stderr) = $api->execManualLocal(
              $command,
              $name);
          }
        } catch (ConduitException $ex) {
        }
      }
    }

    if ($err) {
      if ($api instanceof ArcanistMercurialAPI) {
        $rev = '';
        if ($start) {
          $rev = csprintf('-r %s', $start);
        }

        $exec = $api->execManualLocal(
          'bookmark %C %s',
          $rev,
          $name);

        if (!$exec[0] && $start) {
          $api->execxLocal('update %s', $name);
        }
      } else {
        $startarg = $start ? csprintf('%s', $start) : '';
        $exec = $api->execManualLocal(
          'checkout --track -b %s %C',
          $name,
          $startarg);
      }

      list($err, $stdout, $stderr) = $exec;
    }

    echo $stdout;
    fprintf(STDERR, $stderr);
    return $err;
  }

  private function loadCommitInfo(array $branches) {
    $repository_api = $this->getRepositoryAPI();

    $futures = array();
    foreach ($branches as $branch) {
      if ($repository_api instanceof ArcanistMercurialAPI) {
        $futures[$branch['name']] = $repository_api->execFutureLocal(
          "log -l 1 --template '%C' -r %s",
          "{node}\1{date|hgdate}\1{p1node}\1{desc|firstline}\1{desc}",
          hgsprintf($branch['name']));

      } else {
        // NOTE: "-s" is an option deep in git's diff argument parser that
        // doesn't seem to have much documentation and has no long form. It
        // suppresses any diff output.
        $futures[$branch['name']] = $repository_api->execFutureLocal(
          'show -s --format=%C %s --',
          '%H%x01%ct%x01%T%x01%s%x01%s%n%n%b',
          $branch['name']);
      }
    }

    $branches = ipull($branches, null, 'name');

    foreach (Futures($futures)->limit(16) as $name => $future) {
      list($info) = $future->resolvex();
      list($hash, $epoch, $tree, $desc, $text) = explode("\1", trim($info), 5);

      $branch = $branches[$name];
      $branch['hash'] = $hash;
      $branch['desc'] = $desc;

      try {
        $message = ArcanistDifferentialCommitMessage::newFromRawCorpus($text);
        $id = $message->getRevisionID();

        $branch += array(
          'epoch'       => (int)$epoch,
          'tree'        => $tree,
          'revisionID'  => $id,
        );
      } catch (ArcanistUsageException $ex) {
        // In case of invalid commit message which fails the parsing,
        // do nothing.
      }

      $branches[$name] = $branch;
    }

    return $branches;
  }

  private function loadRevisions(array $branches) {
    $ids = array();
    $hashes = array();

    foreach ($branches as $branch) {
      if ($branch['revisionID']) {
        $ids[] = $branch['revisionID'];
      }
      $hashes[] = array('gtcm', $branch['hash']);
      $hashes[] = array('gttr', $branch['tree']);
    }

    $calls = array();

    if ($ids) {
      $calls[] = $this->getConduit()->callMethod(
        'differential.query',
        array(
          'ids' => $ids,
        ));
    }

    if ($hashes) {
      $calls[] = $this->getConduit()->callMethod(
        'differential.query',
        array(
          'commitHashes' => $hashes,
        ));
    }

    $results = array();
    foreach (Futures($calls) as $call) {
      $results[] = $call->resolve();
    }

    return array_mergev($results);
  }

  private function printBranches(array $branches, array $revisions) {
    $revisions = ipull($revisions, null, 'id');

    static $color_map = array(
      'Closed'          => 'cyan',
      'Needs Review'    => 'magenta',
      'Needs Revision'  => 'red',
      'Accepted'        => 'green',
      'No Revision'     => 'blue',
      'Abandoned'       => 'default',
    );

    static $ssort_map = array(
      'Closed'          => 1,
      'No Revision'     => 2,
      'Needs Review'    => 3,
      'Needs Revision'  => 4,
      'Accepted'        => 5,
    );

    $out = array();
    foreach ($branches as $branch) {
      $revision = idx($revisions, idx($branch, 'revisionID'));

      // If we haven't identified a revision by ID, try to identify it by hash.
      if (!$revision) {
        foreach ($revisions as $rev) {
          $hashes = idx($rev, 'hashes', array());
          foreach ($hashes as $hash) {
            if (($hash[0] == 'gtcm' && $hash[1] == $branch['hash']) ||
                ($hash[0] == 'gttr' && $hash[1] == $branch['tree'])) {
              $revision = $rev;
              break;
            }
          }
        }
      }

      if ($revision) {
        $desc = 'D'.$revision['id'].': '.$revision['title'];
        $status = $revision['statusName'];
      } else {
        $desc = $branch['desc'];
        $status = 'No Revision';
      }

      if (!$this->getArgument('view-all') && !$branch['current']) {
        if ($status == 'Closed' || $status == 'Abandoned') {
          continue;
        }
      }

      $epoch = $branch['epoch'];

      $color = idx($color_map, $status, 'default');
      $ssort = sprintf('%d%012d', idx($ssort_map, $status, 0), $epoch);

      $out[] = array(
        'name'      => $branch['name'],
        'current'   => $branch['current'],
        'status'    => $status,
        'desc'      => $desc,
        'color'     => $color,
        'esort'     => $epoch,
        'ssort'     => $ssort,
      );
    }

    $len_name = max(array_map('strlen', ipull($out, 'name'))) + 2;
    $len_status = max(array_map('strlen', ipull($out, 'status'))) + 2;

    if ($this->getArgument('by-status')) {
      $out = isort($out, 'ssort');
    } else {
      $out = isort($out, 'esort');
    }

    $console = PhutilConsole::getConsole();
    foreach ($out as $line) {
      $color = $line['color'];
      $console->writeOut(
        "%s **%s** <fg:{$color}>%s</fg> %s\n",
        $line['current'] ? '* ' : '  ',
        str_pad($line['name'], $len_name),
        str_pad($line['status'], $len_status),
        $line['desc']);
    }
  }

}
