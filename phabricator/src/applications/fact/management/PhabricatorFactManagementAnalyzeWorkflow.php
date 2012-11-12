<?php

final class PhabricatorFactManagementAnalyzeWorkflow
  extends PhabricatorFactManagementWorkflow {

  public function didConstruct() {
    $this
      ->setName('analyze')
      ->setSynopsis(pht('Manually invoke fact analyzers.'))
      ->setArguments(
        array(
          array(
            'name'    => 'iterator',
            'param'   => 'name',
            'repeat'  => true,
            'help'    => 'Process only iterator __name__.',
          ),
          array(
            'name'    => 'all',
            'help'    => 'Analyze from the beginning, ignoring cursors.',
          ),
          array(
            'name'    => 'skip-aggregates',
            'help'    => 'Skip analysis of aggreate facts.',
          ),
        ));
  }

  public function execute(PhutilArgumentParser $args) {
    $console = PhutilConsole::getConsole();

    $daemon = new PhabricatorFactDaemon(array());
    $daemon->setVerbose(true);
    $daemon->setEngines(PhabricatorFactEngine::loadAllEngines());

    $iterators = PhabricatorFactDaemon::getAllApplicationIterators();

    $selected = $args->getArg('iterator');
    if ($selected) {
      $use = array();
      foreach ($selected as $iterator_name) {
        if (isset($iterators[$iterator_name])) {
          $use[$iterator_name] = $iterators[$iterator_name];
        } else {
          $console->writeErr(
            "%s\n",
            pht("Iterator '%s' does not exist.", $iterator_name));
        }
      }
      $iterators = $use;
    }

    foreach ($iterators as $iterator_name => $iterator) {
      if ($args->getArg('all')) {
        $daemon->processIterator($iterator);
      } else {
        $daemon->processIteratorWithCursor($iterator_name, $iterator);
      }
    }

    if (!$args->getArg('skip-aggregates')) {
      $daemon->processAggregates();
    }

    return 0;
  }

}
