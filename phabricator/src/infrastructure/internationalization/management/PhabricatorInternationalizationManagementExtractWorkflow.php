<?php

final class PhabricatorInternationalizationManagementExtractWorkflow
  extends PhabricatorInternationalizationManagementWorkflow {

  public function didConstruct() {
    $this
      ->setName('extract')
      ->setSynopsis(pht('Extract translatable strings.'))
      ->setArguments(
        array(
          array(
            'name' => 'paths',
            'wildcard' => true,
          ),
        ));
  }

  public function execute(PhutilArgumentParser $args) {
    $console = PhutilConsole::getConsole();
    $paths = $args->getArg('paths');

    $futures = array();
    foreach ($paths as $path) {
      $root = Filesystem::resolvePath($path);
      $path_files = id(new FileFinder($root))
        ->withType('f')
        ->withSuffix('php')
        ->find();

      foreach ($path_files as $file) {
        $full_path = $root.DIRECTORY_SEPARATOR.$file;
        $data = Filesystem::readFile($full_path);
        $futures[$full_path] = xhpast_get_parser_future($data);
      }
    }

    $console->writeOut(
      "%s\n",
      pht('Found %s file(s)...', new PhutilNumber(count($futures))));

    $results = array();

    $bar = id(new PhutilConsoleProgressBar())
      ->setTotal(count($futures));
    foreach (Futures($futures)->limit(8) as $full_path => $future) {
      $bar->update(1);

      $tree = XHPASTTree::newFromDataAndResolvedExecFuture(
        Filesystem::readFile($full_path),
        $future->resolve());

      $root = $tree->getRootNode();
      $calls = $root->selectDescendantsOfType('n_FUNCTION_CALL');
      foreach ($calls as $call) {
        $name = $call->getChildByIndex(0)->getConcreteString();
        if ($name == 'pht') {
          $params = $call->getChildByIndex(1, 'n_CALL_PARAMETER_LIST');
          $string_node = $params->getChildByIndex(0);
          $string_line = $string_node->getLineNumber();
          try {
            $string_value = $string_node->evalStatic();

            $results[$string_value][] = array(
              'file' => Filesystem::readablePath($full_path),
              'line' => $string_line,
            );
          } catch (Exception $ex) {
            // TODO: Deal with this junks.
          }
        }
      }

      $tree->dispose();
    }
    $bar->done();

    ksort($results);

    $out = array();
    $out[] = '<?php';
    $out[] = '// @nolint';
    $out[] = 'return array(';
    foreach ($results as $string => $locations) {
      foreach ($locations as $location) {
        $out[] = '  // '.$location['file'].':'.$location['line'];
      }
      $out[] = "  '".addcslashes($string, "\0..\37\\'\177..\377")."' => null,";
      $out[] = null;
    }
    $out[] = ');';
    $out[] = null;

    echo implode("\n", $out);

    return 0;
  }

}
