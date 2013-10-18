<?php

final class PhabricatorRemarkupBlockInterpreterCowsay
  extends PhutilRemarkupBlockInterpreter {

  public function getInterpreterName() {
    return 'cowsay';
  }

  public function markupContent($content, array $argv) {
    if (!Filesystem::binaryExists('cowsay')) {
      return $this->markupError(
        pht('Unable to locate the `cowsay` binary. Install cowsay.'));
    }

    $bin = idx($argv, 'think') ? 'cowthink' : 'cowsay';
    $eyes = idx($argv, 'eyes', 'oo');
    $tongue = idx($argv, 'tongue', '  ');
    $cow = idx($argv, 'cow', 'default');

    // NOTE: Strip this aggressively to prevent nonsense like
    // `cow=/etc/passwd`. We could build a whiltelist with `cowsay -l`.
    $cow = preg_replace('/[^a-z.-]+/', '', $cow);

    $future = new ExecFuture(
      '%s -e %s -T %s -f %s ',
      $bin,
      $eyes,
      $tongue,
      $cow);

    $future->write($content);

    list($err, $stdout, $stderr) = $future->resolve();

    if ($err) {
      return $this->markupError(
        pht(
          'Execution of `cowsay` failed:', $stderr));
    }


    if ($this->getEngine()->isTextMode()) {
      return $stdout;
    }

    return phutil_tag(
      'div',
      array(
        'class' => 'PhabricatorMonospaced remarkup-cowsay',
      ),
      $stdout);
  }

}
