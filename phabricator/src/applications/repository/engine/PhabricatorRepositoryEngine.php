<?php

/**
 * @task config     Configuring Repository Engines
 * @task internal   Internals
 */
abstract class PhabricatorRepositoryEngine {

  private $repository;
  private $verbose;

  /**
   * @task config
   */
  public function setRepository(PhabricatorRepository $repository) {
    $this->repository = $repository;
    return $this;
  }


  /**
   * @task config
   */
  protected function getRepository() {
    if ($this->repository === null) {
      throw new Exception("Call setRepository() to provide a repository!");
    }

    return $this->repository;
  }


  /**
   * @task config
   */
  public function setVerbose($verbose) {
    $this->verbose = $verbose;
    return $this;
  }


  /**
   * @task config
   */
  public function getVerbose() {
    return $this->verbose;
  }


  /**
   * @task internal
   */
  protected function log($pattern /* ... */) {
    if ($this->getVerbose()) {
      $console = PhutilConsole::getConsole();
      $argv = func_get_args();
      call_user_func_array(array($console, 'writeLog'), $argv);
    }
    return $this;
  }

}
