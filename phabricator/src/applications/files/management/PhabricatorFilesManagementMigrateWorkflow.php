<?php

final class PhabricatorFilesManagementMigrateWorkflow
  extends PhabricatorFilesManagementWorkflow {

  public function didConstruct() {
    $this
      ->setName('migrate')
      ->setSynopsis('Migrate files between storage engines.')
      ->setArguments(
        array(
          array(
            'name'      => 'engine',
            'param'     => 'storage_engine',
            'help'      => 'Migrate to the named storage engine.',
          ),
          array(
            'name'      => 'dry-run',
            'help'      => 'Show what would be migrated.',
          ),
          array(
            'name'      => 'all',
            'help'      => 'Migrate all files.',
          ),
          array(
            'name'      => 'names',
            'wildcard'  => true,
          ),
        ));
  }

  public function execute(PhutilArgumentParser $args) {
    $console = PhutilConsole::getConsole();

    $engine_id = $args->getArg('engine');
    if (!$engine_id) {
      throw new PhutilArgumentUsageException(
        "Specify an engine to migrate to with `--engine`. ".
        "Use `files engines` to get a list of engines.");
    }

    $engine = PhabricatorFile::buildEngine($engine_id);

    $iterator = $this->buildIterator($args);
    if (!$iterator) {
      throw new PhutilArgumentUsageException(
        "Either specify a list of files to migrate, or use `--all` ".
        "to migrate all files.");
    }

    $is_dry_run = $args->getArg('dry-run');

    $failed = array();

    foreach ($iterator as $file) {
      $fid = 'F'.$file->getID();

      if ($file->getStorageEngine() == $engine_id) {
        $console->writeOut(
          "%s: Already stored on '%s'\n",
          $fid,
          $engine_id);
        continue;
      }

      if ($is_dry_run) {
        $console->writeOut(
          "%s: Would migrate from '%s' to '%s' (dry run)\n",
          $fid,
          $file->getStorageEngine(),
          $engine_id);
        continue;
      }

      $console->writeOut(
        "%s: Migrating from '%s' to '%s'...",
        $fid,
        $file->getStorageEngine(),
        $engine_id);

      try {
        $file->migrateToEngine($engine);
        $console->writeOut("done.\n");
      } catch (Exception $ex) {
        $console->writeOut("failed!\n");
        $console->writeErr("%s\n", (string)$ex);
        $failed[] = $file;
      }
    }

    if ($failed) {
      $console->writeOut("**Failures!**\n");
      $ids = array();
      foreach ($failed as $file) {
        $ids[] = 'F'.$file->getID();
      }
      $console->writeOut("%s\n", implode(', ', $ids));

      return 1;
    } else {
      $console->writeOut("**Success!**\n");
      return 0;
    }
  }

}
