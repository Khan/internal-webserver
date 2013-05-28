#!/usr/bin/env php
<?php

$root = dirname(dirname(dirname(__FILE__)));
require_once $root.'/scripts/__init_script__.php';

$args = new PhutilArgumentParser($argv);
$args->setTagline('manage mail');
$args->setSynopsis(<<<EOSYNOPSIS
**cache** __command__ [__options__]
    Manage Phabricator caches.

EOSYNOPSIS
  );
$args->parseStandardArguments();

$workflows = array(
  new PhabricatorCacheManagementPurgeWorkflow(),
  new PhutilHelpArgumentWorkflow(),
);

$args->parseWorkflows($workflows);
