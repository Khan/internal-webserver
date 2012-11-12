#!/usr/bin/env php
<?php

require_once dirname(__FILE__).'/__init_script__.php';
require_once dirname(__FILE__).'/lib/PhutilLibraryMapBuilder.php';

$args = new PhutilArgumentParser($argv);
$args->setTagline('rebuild the library map file');
$args->setSynopsis(<<<EOHELP
    **phutil_rebuild_map.php** [__options__] __root__
        Rebuild the library map file for a libphutil library.

EOHELP
);

$args->parseStandardArguments();
$args->parse(
  array(
    array(
      'name'      => 'quiet',
      'help'      => 'Do not write status messages to stderr.',
    ),
    array(
      'name'      => 'drop-cache',
      'help'      => 'Drop the symbol cache and rebuild the entire map from '.
                     'scratch.',
    ),
    array(
      'name'      => 'limit',
      'param'     => 'N',
      'default'   => 8,
      'help'      => 'Controls the number of symbol mapper subprocesses run '.
                     'at once. Defaults to 8.',
    ),
    array(
      'name'      => 'show',
      'help'      => 'Print symbol map to stdout instead of writing it to the '.
                     'map file.',
    ),
    array(
      'name'      => 'ugly',
      'help'      => 'Use faster but less readable serialization for --show.',
    ),
    array(
      'name'      => 'root',
      'wildcard'  => true,
    )
  ));

$root = $args->getArg('root');
if (count($root) !== 1) {
  throw new Exception("Provide exactly one library root!");
}
$root = Filesystem::resolvePath(head($root));

$builder = new PhutilLibraryMapBuilder($root);

$builder->setQuiet($args->getArg('quiet'));
$builder->setSubprocessLimit($args->getArg('limit'));

if ($args->getArg('drop-cache')) {
  $builder->dropSymbolCache();
}

if ($args->getArg('show')) {
  $builder->setShowMap(true);
  $builder->setUgly($args->getArg('ugly'));
}

$builder->buildMap();

exit(0);
