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
final class ArcanistPhpcsLinter extends ArcanistLinter {

  private $reports;

  public function getLinterName() {
    return 'PHPCS';
  }

  public function getLintSeverityMap() {
    return array();
  }

  public function getLintNameMap() {
    return array();
  }

  public function getPhpcsOptions() {
    $working_copy = $this->getEngine()->getWorkingCopy();

    $options = $working_copy->getConfig('lint.phpcs.options');

    $standard = $working_copy->getConfig('lint.phpcs.standard');
    $options .= !empty($standard) ? ' --standard=' . $standard : '';

    return $options;
  }

  private function getPhpcsPath() {
    $working_copy = $this->getEngine()->getWorkingCopy();
    $bin = $working_copy->getConfig('lint.phpcs.bin');

    if ($bin === null) {
      $bin = 'phpcs';
    }

    return $bin;
  }

  public function willLintPaths(array $paths) {
    $phpcs_bin = $this->getPhpcsPath();
    $phpcs_options = $this->getPhpcsOptions();
    $futures = array();

    foreach ($paths as $path) {
      $filepath = $this->getEngine()->getFilePathOnDisk($path);
      $this->reports[$path] = new TempFile();
      $futures[$path] = new ExecFuture('%C %C --report=xml --report-file=%s %s',
        $phpcs_bin,
        $phpcs_options,
        $this->reports[$path],
        $filepath);
    }

    foreach (Futures($futures)->limit(8) as $path => $future) {
      $this->results[$path] = $future->resolve();
    }

    libxml_use_internal_errors(true);
  }

  public function lintPath($path) {
    list($rc, $stdout) = $this->results[$path];

    $report = Filesystem::readFile($this->reports[$path]);

    if ($report) {
      $report_dom = new DOMDocument();
      libxml_clear_errors();
      $report_dom->loadXML($report);
    }
    if (!$report || libxml_get_errors()) {
      throw new ArcanistUsageException('PHPCS Linter failed to load ' .
        'reporting file. Something happened when running phpcs. ' .
        "Output:\n$stdout" .
        "\nTry running lint with --trace flag to get more details.");
    }

    $files = $report_dom->getElementsByTagName('file');
    foreach ($files as $file) {
      foreach ($file->childNodes as $child) {
        if (!($child instanceof DOMElement)) {
          continue;
        }

        $data = $this->getData($path);
        $lines = explode("\n", $data);
        $line = $lines[$child->getAttribute('line') - 1];
        $text = substr($line, $child->getAttribute('column') - 1);
        $name = $this->getLinterName() . ' - ' . $child->getAttribute('source');
        $severity = $child->tagName == 'error' ?
            ArcanistLintSeverity::SEVERITY_ERROR
            : ArcanistLintSeverity::SEVERITY_WARNING;

        $message = new ArcanistLintMessage();
        $message->setPath($path);
        $message->setLine($child->getAttribute('line'));
        $message->setChar($child->getAttribute('column'));
        $message->setCode($child->getAttribute('severity'));
        $message->setName($name);
        $message->setDescription($child->nodeValue);
        $message->setSeverity($severity);
        $message->setOriginalText($text);
        $this->addLintMessage($message);
      }
    }
  }
}
