<?php

final class PhabricatorWorkingCopyDiscoveryTestCase
  extends PhabricatorWorkingCopyTestCase {

  public function testSubversionCommitDiscovery() {
    $repo = $this->buildPulledRepository('ST');

    $engine = id(new PhabricatorRepositoryDiscoveryEngine())
      ->setRepository($repo);

    $refs = $engine->discoverCommits($repo);
    $this->assertEqual(
      array(
        1368319433,
        1368319448,
      ),
      mpull($refs, 'getEpoch'),
      'Commit Epochs');

    // The next time through, these should be cached as already discovered.

    $refs = $engine->discoverCommits($repo);
    $this->assertEqual(array(), $refs);
  }

  public function testExecuteGitVerifySameOrigin() {
    $cases = array(
      array(
        'ssh://user@domain.com/path.git',
        'ssh://user@domain.com/path.git',
        true,
        'Identical paths should pass.',
      ),
      array(
        'ssh://user@domain.com/path.git',
        'https://user@domain.com/path.git',
        true,
        'Protocol changes should pass.',
      ),
      array(
        'ssh://user@domain.com/path.git',
        'git@domain.com:path.git',
        true,
        'Git implicit SSH should pass.',
      ),
      array(
        'ssh://user@gitserv001.com/path.git',
        'ssh://user@gitserv002.com/path.git',
        true,
        'Domain changes should pass.',
      ),
      array(
        'ssh://alincoln@domain.com/path.git',
        'ssh://htaft@domain.com/path.git',
        true,
        'User/auth changes should pass.',
      ),
      array(
        'ssh://user@domain.com/apples.git',
        'ssh://user@domain.com/bananas.git',
        false,
        'Path changes should fail.',
      ),
      array(
        'ssh://user@domain.com/apples.git',
        'git@domain.com:bananas.git',
        false,
        'Git implicit SSH path changes should fail.',
      ),
      array(
        'user@domain.com:path/repo.git',
        'user@domain.com:path/repo',
        true,
        'Optional .git extension should not prevent matches.',
      ),
      array(
        'user@domain.com:path/repo/',
        'user@domain.com:path/repo',
        true,
        'Optional trailing slash should not prevent matches.',
      ),
      array(
        'file:///path/to/local/repo.git',
        'file:///path/to/local/repo.git',
        true,
        'file:// protocol should be supported.',
      ),
      array(
        '/path/to/local/repo.git',
        'file:///path/to/local/repo.git',
        true,
        'Implicit file:// protocol should be recognized.',
      ),
    );

    foreach ($cases as $case) {
      list($remote, $config, $expect, $message) = $case;

      $this->assertEqual(
        $expect,
        PhabricatorRepositoryPullLocalDaemon::isSameGitOrigin(
          $remote,
          $config,
          '(a test case)'),
        "Verification that '{$remote}' and '{$config}' are the same origin ".
        "had a different outcome than expected: {$message}");
    }
  }

}
