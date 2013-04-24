#!/usr/bin/env php
<?php

require_once dirname(dirname(__FILE__)).'/__init_script__.php';

$args = new PhutilArgumentParser($argv);
$args->setTagline('regenerate CSS sprite sheets');
$args->setSynopsis(<<<EOHELP
**sprites**
    Rebuild CSS sprite sheets.

EOHELP
);
$args->parseStandardArguments();
$args->parse(
  array(
    array(
      'name'  => 'force',
      'help'  => 'Force regeneration even if sources have not changed.',
    ),
  ));

$root = dirname(phutil_get_library_root('phabricator'));
$webroot = $root.'/webroot/rsrc';
$webroot = Filesystem::readablePath($webroot);

$generator = new CeleritySpriteGenerator();

$sheets = array(
  'icon' => $generator->buildIconSheet(),
  'menu' => $generator->buildMenuSheet(),
  'apps' => $generator->buildAppsSheet(),
  'actions' => $generator->buildActionsSheet(),
  'minicons' => $generator->buildMiniconsSheet(),
  'conpherence' => $generator->buildConpherenceSheet(),
  'apps-large' => $generator->buildAppsLargeSheet(),
  'payments' => $generator->buildPaymentsSheet(),
  'tokens' => $generator->buildTokenSheet(),
  'docs' => $generator->buildDocsSheet(),
  'gradient' => $generator->buildGradientSheet(),
);

list($err) = exec_manual('optipng');
if ($err) {
  $have_optipng = false;
  echo phutil_console_format(
    "<bg:red> WARNING </bg> `optipng` not found in PATH.\n".
    "Sprites will not be optimized! Install `optipng`!\n");
} else {
  $have_optipng = true;
}

foreach ($sheets as $name => $sheet) {
  $manifest_path = $root.'/resources/sprite/manifest/'.$name.'.json';
  if (!$args->getArg('force')) {
    if (Filesystem::pathExists($manifest_path)) {
      $data = Filesystem::readFile($manifest_path);
      $data = json_decode($data, true);
      if (!$sheet->needsRegeneration($data)) {
        continue;
      }
    }
  }

  $sheet
    ->generateCSS($webroot."/css/sprite-{$name}.css")
    ->generateManifest($root."/resources/sprite/manifest/{$name}.json");

  foreach ($sheet->getScales() as $scale) {
    if ($scale == 1) {
      $sheet_name = "sprite-{$name}.png";
    } else {
      $sheet_name = "sprite-{$name}-X{$scale}.png";
    }

    $full_path = "{$webroot}/image/{$sheet_name}";
    $sheet->generateImage($full_path, $scale);

    if ($have_optipng) {
      echo "Optimizing...\n";
      phutil_passthru('optipng -o7 -clobber %s', $full_path);
    }
  }
}

echo "Done.\n";
