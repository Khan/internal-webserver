#!/usr/bin/env php
<?php

$root = dirname(dirname(dirname(__FILE__)));
require_once $root.'/scripts/__init_script__.php';

$args = new PhutilArgumentParser($argv);
$args->setTagline('manage fact configuration');
$args->setSynopsis(<<<EOSYNOPSIS
**fact** __command__ [__options__]
    Manage and debug Phabricator data extraction, storage and
    configuration used to compute statistics.

EOSYNOPSIS
  );
$args->parseStandardArguments();

$workflows = array(
  new PhabricatorFactManagementDestroyWorkflow(),
  new PhabricatorFactManagementAnalyzeWorkflow(),
  new PhabricatorFactManagementStatusWorkflow(),
  new PhabricatorFactManagementListWorkflow(),
  new PhabricatorFactManagementCursorsWorkflow(),
  new PhutilHelpArgumentWorkflow(),
);

$args->parseWorkflows($workflows);
