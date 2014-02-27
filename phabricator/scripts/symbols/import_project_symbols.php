#!/usr/bin/env php
<?php


$root = dirname(dirname(dirname(__FILE__)));
require_once $root.'/scripts/__init_script__.php';

$args = new PhutilArgumentParser($argv);
$args->setSynopsis(<<<EOSYNOPSIS
**import_project_symbols.php** [__options__] __project_name__ < symbols

  Import project symbols (symbols are read from stdin).
EOSYNOPSIS
  );
$args->parseStandardArguments();
$args->parse(
  array(
    array(
      'name'      => 'no-purge',
      'help'      => 'Do not clear all symbols for this project before '.
                     'uploading new symbols. Useful for incremental updating.',
    ),
    array(
      'name'      => 'ignore-errors',
      'help'      => 'If a line can\'t be parsed, ignore that line and '.
                     'continue instead of exiting.',
    ),
    array(
      'name'      => 'max-transaction',
      'param'     => 'num-syms',
      'default'  => '100000',
      'help'      => 'Maximum number of symbols that should '.
                     'be part of a single transaction',
    ),
    array(
      'name'      => 'more',
      'wildcard'  => true,
    ),
  ));

$more = $args->getArg('more');
if (count($more) !== 1) {
  $args->printHelpAndExit();
}

$project_name = head($more);
$project = id(new PhabricatorRepositoryArcanistProject())->loadOneWhere(
  'name = %s',
  $project_name);

if (!$project) {
  // TODO: Provide a less silly way to do this explicitly, or just do it right
  // here.
  echo "Project '{$project_name}' is unknown. Upload a diff to implicitly ".
       "create it.\n";
  exit(1);
}

echo "Parsing input from stdin...\n";
$input = file_get_contents('php://stdin');
$input = trim($input);
$input = explode("\n", $input);


function commit_symbols ($syms, $project, $no_purge) {
  echo "Looking up path IDs...\n";
  $path_map =
    PhabricatorRepositoryCommitChangeParserWorker::lookupOrCreatePaths(
           ipull($syms, 'path'));

  $symbol = new PhabricatorRepositorySymbol();
  $conn_w = $symbol->establishConnection('w');

  echo "Preparing queries...\n";
  $sql = array();
  foreach ($syms as $dict) {
    $sql[] = qsprintf(
                      $conn_w,
                      '(%d, %s, %s, %s, %s, %d, %d)',
                      $project->getID(),
                      $dict['ctxt'],
                      $dict['name'],
                      $dict['type'],
                      $dict['lang'],
                      $dict['line'],
                      $path_map[$dict['path']]);
  }

  if (!$no_purge) {
    echo "Purging old syms...\n";
    queryfx($conn_w,
            'DELETE FROM %T WHERE arcanistProjectID = %d',
            $symbol->getTableName(),
            $project->getID());
  }

  echo "Loading ".number_format(count($sql))." syms...\n";
  foreach (array_chunk($sql, 128) as $chunk) {
    queryfx($conn_w,
            'INSERT INTO %T
      (arcanistProjectID, symbolContext, symbolName, symbolType,
        symbolLanguage, lineNumber, pathID) VALUES %Q',
            $symbol->getTableName(),
            implode(', ', $chunk));
  }

}

function check_string_value($value, $field_name, $line_no, $max_length) {
   if (strlen($value) > $max_length) {
      throw new Exception(
        "{$field_name} '{$value}' defined on line #{$line_no} is too long, ".
        "maximum {$field_name} length is {$max_length} characters.");
    }

    if (!phutil_is_utf8_with_only_bmp_characters($value)) {
      throw new Exception(
        "{$field_name} '{$value}' defined on line #{$line_no} is not a valid ".
        "UTF-8 string, ".
        "it should contain only UTF-8 characters.");
    }
}

$no_purge = $args->getArg('no-purge');
$symbols = array();
foreach ($input as $key => $line) {
  try {
    $line_no = $key + 1;
    $matches = null;
    $ok = preg_match(
      '/^((?P<context>[^ ]+)? )?(?P<name>[^ ]+) (?P<type>[^ ]+) '.
      '(?P<lang>[^ ]+) (?P<line>\d+) (?P<path>.*)$/',
      $line,
      $matches);
    if (!$ok) {
      throw new Exception(
        "Line #{$line_no} of input is invalid. Expected five or six ".
        "space-delimited fields: maybe symbol context, symbol name, symbol ".
        "type, symbol language, line number, path. ".
        "For example:\n\n".
        "idx function php 13 /path/to/some/file.php\n\n".
        "Actual line was:\n\n".
        "{$line}");
    }
    if (empty($matches['context'])) {
      $matches['context'] = '';
    }
    $context     = $matches['context'];
    $name        = $matches['name'];
    $type        = $matches['type'];
    $lang        = $matches['lang'];
    $line_number = $matches['line'];
    $path        = $matches['path'];

    check_string_value($context, 'Symbol context', $line_no, 128);
    check_string_value($name, 'Symbol name', $line_no, 128);
    check_string_value($type, 'Symbol type', $line_no, 12);
    check_string_value($lang, 'Symbol language', $line_no, 32);
    check_string_value($path, 'Path', $line_no, 512);

    if (!strlen($path) || $path[0] != '/') {
      throw new Exception(
        "Path '{$path}' defined on line #{$line_no} is invalid. Paths should ".
        "begin with '/' and specify a path from the root of the project, like ".
        "'/src/utils/utils.php'.");
    }

    $symbols[] = array(
      'ctxt' => $context,
      'name' => $name,
      'type' => $type,
      'lang' => $lang,
      'line' => $line_number,
      'path' => $path,
    );
  } catch (Exception $e) {
    if ($args->getArg('ignore-errors')) {
      continue;
    } else {
      throw $e;
    }
  }

  if (count ($symbols) >= $args->getArg('max-transaction')) {
      try {
        echo "Committing {$args->getArg('max-transaction')} symbols....\n";
        commit_symbols($symbols, $project, $no_purge);
        $no_purge = true;
        unset($symbols);
        $symbols = array();
      } catch (Exception $e) {
        if ($args->getArg('ignore-errors')) {
          continue;
        } else {
          throw $e;
        }
      }
  }
}

if (count($symbols)) {
  commit_symbols($symbols, $project, $no_purge);
}

echo "Done.\n";
