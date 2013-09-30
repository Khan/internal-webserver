<?php

abstract class PhabricatorFilesManagementWorkflow
  extends PhutilArgumentWorkflow {

  public function isExecutable() {
    return true;
  }

  protected function buildIterator(PhutilArgumentParser $args) {
    $names = $args->getArg('names');

    if ($args->getArg('all')) {
      if ($names) {
        throw new PhutilArgumentUsageException(
          "Specify either a list of files or `--all`, but not both.");
      }
      return new LiskMigrationIterator(new PhabricatorFile());
    }

    if ($names) {
      $query = id(new PhabricatorObjectQuery())
        ->setViewer(PhabricatorUser::getOmnipotentUser())
        ->withNames($names)
        ->withTypes(array(PhabricatorFilePHIDTypeFile::TYPECONST));

      $query->execute();
      $files = $query->getNamedResults();

      foreach ($names as $name) {
        if (empty($files[$name])) {
          throw new PhutilArgumentUsageException(
            "No file '{$name}' exists!");
        }
      }

      return array_values($files);
    }

    return null;
  }


}
