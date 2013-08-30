<?php

/**
 * Lint engine for use in constructing test cases. See
 * @{class:ArcanistLinterTestCase}.
 *
 * @group testcase
 */
final class UnitTestableArcanistLintEngine extends ArcanistLintEngine {

  protected $linters = array();

  public function addLinter($linter) {
    $this->linters[] = $linter;
    return $this;
  }

  public function addFileData($path, $data) {
    $this->fileData[$path] = $data;
    return $this;
  }

  public function pathExists($path) {
    if (idx($this->fileData, $path)) {
      return true;
    }
    return parent::pathExists($path);
  }

  protected function buildLinters() {
    return $this->linters;
  }

}
