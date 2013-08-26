<?php

/**
 * Uses `ruby` to detect various errors in Ruby code.
 *
 * @group linter
 */
final class ArcanistRubyLinter extends ArcanistExternalLinter {

  public function getLinterName() {
    return 'RUBY';
  }

  public function getLinterConfigurationName() {
    return 'ruby';
  }

  public function getDefaultBinary() {
    // TODO: Deprecation warning.
    $working_copy = $this->getEngine()->getWorkingCopy();
    $prefix = $working_copy->getConfig('lint.ruby.prefix');
    if ($prefix !== null) {
      $ruby_bin = $prefix.'ruby';
    }

    return 'ruby';
  }

  public function getInstallInstructions() {
    return pht('Install `ruby` from <http://www.ruby-lang.org/>.');
  }

  public function supportsReadDataFromStdin() {
    return true;
  }

  public function shouldExpectCommandErrors() {
    return true;
  }

  protected function getMandatoryFlags() {
    // -w: turn on warnings
    // -c: check syntax
    return '-w -c';
  }

  protected function getDefaultMessageSeverity($code) {
    return ArcanistLintSeverity::SEVERITY_ERROR;
  }

  protected function parseLinterOutput($path, $err, $stdout, $stderr) {
    $lines = phutil_split_lines($stderr, $retain_endings = false);

    $messages = array();
    foreach ($lines as $line) {
      $matches = null;

      if (!preg_match("/(.*?):(\d+): (.*?)$/", $line, $matches)) {
        continue;
      }

      foreach ($matches as $key => $match) {
        $matches[$key] = trim($match);
      }

      $code = head(explode(',', $matches[3]));

      $message = new ArcanistLintMessage();
      $message->setPath($path);
      $message->setLine($matches[2]);
      $message->setCode($this->getLinterName());
      $message->setName(pht('Syntax Error'));
      $message->setDescription($matches[3]);
      $message->setSeverity($this->getLintMessageSeverity($code));

      $messages[] = $message;
    }

    if ($err && !$messages) {
      return false;
    }

    return $messages;
  }

}
