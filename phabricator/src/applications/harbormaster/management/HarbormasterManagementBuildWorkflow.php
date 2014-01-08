<?php

final class HarbormasterManagementBuildWorkflow
  extends HarbormasterManagementWorkflow {

  public function didConstruct() {
    $this
      ->setName('build')
      ->setExamples('**build** [__options__] __buildable__ --plan __id__')
      ->setSynopsis(pht('Run plan __id__ on __buildable__.'))
      ->setArguments(
        array(
          array(
            'name' => 'plan',
            'param' => 'id',
            'help' => pht('ID of build plan to run.'),
          ),
          array(
            'name'        => 'buildable',
            'wildcard'    => true,
          ),
        ));
  }

  public function execute(PhutilArgumentParser $args) {
    $viewer = $this->getViewer();

    $names = $args->getArg('buildable');
    if (count($names) != 1) {
      throw new PhutilArgumentUsageException(
        pht('Specify exactly one buildable, by object name.'));
    }

    $name = head($names);

    $buildable = id(new PhabricatorObjectQuery())
      ->setViewer($viewer)
      ->withNames($names)
      ->executeOne();
    if (!$buildable) {
      throw new PhutilArgumentUsageException(
        pht('No such buildable "%s"!', $name));
    }

    if (!($buildable instanceof HarbormasterBuildableInterface)) {
      throw new PhutilArgumentUsageException(
        pht('Object "%s" is not a buildable!', $name));
    }

    $plan_id = $args->getArg('plan');
    if (!$plan_id) {
      throw new PhutilArgumentUsageException(
        pht('Use --plan to specify a build plan to run.'));
    }

    $plan = id(new HarbormasterBuildPlanQuery())
      ->setViewer($viewer)
      ->withIDs(array($plan_id))
      ->executeOne();
    if (!$plan) {
      throw new PhutilArgumentUsageException(
        pht('Build plan "%s" does not exist.', $plan_id));
    }

    $console = PhutilConsole::getConsole();

    $buildable = HarbormasterBuildable::initializeNewBuildable($viewer)
      ->setIsManualBuildable(true)
      ->setBuildablePHID($buildable->getHarbormasterBuildablePHID())
      ->setContainerPHID($buildable->getHarbormasterContainerPHID())
      ->save();

    $console->writeOut(
      "%s\n",
      pht(
        'Applying plan %s to new buildable %s...',
        $plan->getID(),
        'B'.$buildable->getID()));

    $console->writeOut(
      "\n    %s\n\n",
      PhabricatorEnv::getProductionURI('/B'.$buildable->getID()));

    PhabricatorWorker::setRunAllTasksInProcess(true);
    $buildable->applyPlan($plan);

    $console->writeOut("%s\n", pht('Done.'));

    return 0;
  }

}
