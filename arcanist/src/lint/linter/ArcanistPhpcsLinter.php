<?php

/**
 * Uses "PHP_CodeSniffer" to detect checkstyle errors in php code.
 * To use this linter, you must install PHP_CodeSniffer.
 * http://pear.php.net/package/PHP_CodeSniffer.
 *
 * Optional configurations in .arcconfig:
 *
 *   lint.phpcs.standard
 *   lint.phpcs.options
 *   lint.phpcs.bin
 *
 * @group linter
 */
final class ArcanistPhpcsLinter extends ArcanistExternalLinter {

  private $reports;

  public function getLinterName() {
    return 'PHPCS';
  }

  public function getLinterConfigurationName() {
    return 'phpcs';
  }

  public function getMandatoryFlags() {
    return '--report=xml';
  }

  public function getInstallInstructions() {
    return pht('Install PHPCS with `pear install PHP_CodeSniffer`.');
  }

  public function getDefaultFlags() {
    // TODO: Deprecation warnings.

    $config = $this->getEngine()->getConfigurationManager();

    $options = $config->getConfigFromAnySource('lint.phpcs.options');

    $standard = $config->getConfigFromAnySource('lint.phpcs.standard');
    $options .= !empty($standard) ? ' --standard=' . $standard : '';

    return $options;
  }

  public function getDefaultBinary() {
    // TODO: Deprecation warnings.
    $config = $this->getEngine()->getConfigurationManager();
    $bin = $config->getConfigFromAnySource('lint.phpcs.bin');
    if ($bin) {
      return $bin;
    }

    return 'phpcs';
  }

  public function shouldExpectCommandErrors() {
    return true;
  }

  protected function parseLinterOutput($path, $err, $stdout, $stderr) {
    // NOTE: Some version of PHPCS after 1.4.6 stopped printing a valid, empty
    // XML document to stdout in the case of no errors. If PHPCS exits with
    // error 0, just ignore output.
    if (!$err) {
      return array();
    }

    $report_dom = new DOMDocument();
    $ok = @$report_dom->loadXML($stdout);
    if (!$ok) {
      return false;
    }

    $files = $report_dom->getElementsByTagName('file');
    $messages = array();
    foreach ($files as $file) {
      foreach ($file->childNodes as $child) {
        if (!($child instanceof DOMElement)) {
          continue;
        }

        if ($child->tagName == 'error') {
          $prefix = 'E';
        } else {
          $prefix = 'W';
        }

        $code = 'PHPCS.'.$prefix.'.'.$child->getAttribute('source');

        $message = new ArcanistLintMessage();
        $message->setPath($path);
        $message->setLine($child->getAttribute('line'));
        $message->setChar($child->getAttribute('column'));
        $message->setCode($code);
        $message->setDescription($child->nodeValue);
        $message->setSeverity($this->getLintMessageSeverity($code));

        $messages[] = $message;
      }
    }

    return $messages;
  }

  protected function getDefaultMessageSeverity($code) {
    if (preg_match('/^PHPCS\\.W\\./', $code)) {
      return ArcanistLintSeverity::SEVERITY_WARNING;
    } else {
      return ArcanistLintSeverity::SEVERITY_ERROR;
    }
  }

  protected function getLintCodeFromLinterConfigurationKey($code) {
    if (!preg_match('/^PHPCS\\.(E|W)\\./', $code)) {
      throw new Exception(
        "Invalid severity code '{$code}', should begin with 'PHPCS.'.");
    }
    return $code;
  }

}
