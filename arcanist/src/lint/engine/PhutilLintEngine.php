<?php

/*
 * Copyright 2012 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * Lint engine which enforces libphutil rules.
 *
 * TODO: Deal with PhabricatorLintEngine extending this and then finalize it.
 *
 * @group linter
 */
class PhutilLintEngine extends ArcanistLintEngine {

  public function buildLinters() {
    $linters = array();

    $paths = $this->getPaths();

    $library_linter = new ArcanistPhutilLibraryLinter();
    $linters[] = $library_linter;
    foreach ($paths as $path) {
      $library_linter->addPath($path);
    }

    // Remaining linters operate on file contents and ignore removed files.
    foreach ($paths as $key => $path) {
      if (!$this->pathExists($path)) {
        unset($paths[$key]);
      }
      if (preg_match('@^externals/@', $path)) {
        // Third-party stuff lives in /externals/; don't run lint engines
        // against it.
        unset($paths[$key]);
      }
    }

    $generated_linter = new ArcanistGeneratedLinter();
    $linters[] = $generated_linter;

    $nolint_linter = new ArcanistNoLintLinter();
    $linters[] = $nolint_linter;

    $text_linter = new ArcanistTextLinter();
    $linters[] = $text_linter;

    $spelling_linter = new ArcanistSpellingLinter();
    $linters[] = $spelling_linter;
    foreach ($paths as $path) {
      $is_text = false;
      if (preg_match('/\.(php|css|js|hpp|cpp|l|y)$/', $path)) {
        $is_text = true;
      }
      if ($is_text) {
        $generated_linter->addPath($path);
        $generated_linter->addData($path, $this->loadData($path));

        $nolint_linter->addPath($path);
        $nolint_linter->addData($path, $this->loadData($path));

        $text_linter->addPath($path);
        $text_linter->addData($path, $this->loadData($path));

        $spelling_linter->addPath($path);
        $spelling_linter->addData($path, $this->loadData($path));
      }
    }

    $name_linter = new ArcanistFilenameLinter();
    $linters[] = $name_linter;
    foreach ($paths as $path) {
      $name_linter->addPath($path);
    }

    $xhpast_linter = new ArcanistXHPASTLinter();
    $xhpast_linter->setCustomSeverityMap(
      array(
        ArcanistXHPASTLinter::LINT_RAGGED_CLASSTREE_EDGE
          => ArcanistLintSeverity::SEVERITY_WARNING,
        ArcanistXHPASTLinter::LINT_PHP_53_FEATURES
          => ArcanistLintSeverity::SEVERITY_ERROR,
        ArcanistXHPASTLinter::LINT_PHP_54_FEATURES
          => ArcanistLintSeverity::SEVERITY_ERROR,
        ArcanistXHPASTLinter::LINT_COMMENT_SPACING
          => ArcanistLintSeverity::SEVERITY_ERROR,
      ));
    $linters[] = $xhpast_linter;
    foreach ($paths as $path) {
      if (preg_match('/\.php$/', $path)) {
        $xhpast_linter->addPath($path);
        $xhpast_linter->addData($path, $this->loadData($path));
      }
    }

    $license_linter = new ArcanistApacheLicenseLinter();
    $linters[] = $license_linter;
    foreach ($paths as $path) {
      if (preg_match('/\.(php|cpp|hpp|l|y)$/', $path)) {
        if (!preg_match('@^externals/@', $path)) {
          $license_linter->addPath($path);
          $license_linter->addData($path, $this->loadData($path));
        }
      }
    }

    return $linters;
  }

}
