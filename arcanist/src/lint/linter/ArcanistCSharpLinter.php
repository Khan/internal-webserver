<?php

/**
 * C# linter for Arcanist.
 *
 * @group linter
 */
final class ArcanistCSharpLinter extends ArcanistLinter {

  private $runtimeEngine;
  private $cslintEngine;
  private $cslintHintPath;
  private $loaded;
  private $discoveryMap;
  private $futures;

  const SUPPORTED_VERSION = 1;

  public function getLinterName() {
    return 'C#';
  }

  public function getLinterConfigurationName() {
    return 'csharp';
  }

  public function getLinterConfigurationOptions() {
    $options = parent::getLinterConfigurationOptions();

    $options["discovery"] = 'map<string, list<string>>';
    $options["binary"] = 'string';

    return $options;
  }

  public function setLinterConfigurationValue($key, $value) {
    switch ($key) {
      case 'discovery':
        $this->discoveryMap = $value;
        return;
      case 'binary':
        $this->cslintHintPath = $value;
        return;
    }
    parent::setLinterConfigurationValue($key, $value);
  }

  public function getLintCodeFromLinterConfigurationKey($code) {
    return $code;
  }

  public function setCustomSeverityMap(array $map) {
    foreach ($map as $code => $severity) {
      if (substr($code, 0, 2) === "SA" && $severity == "disabled") {
        throw new Exception(
          "In order to keep StyleCop integration with IDEs and other tools ".
          "consistent with Arcanist results, you aren't permitted to ".
          "disable StyleCop rules within '.arclint'.  ".
          "Instead configure the severity using the StyleCop settings dialog ".
          "(usually accessible from within your IDE).  StyleCop settings ".
          "for your project will be used when linting for Arcanist.");
      }
    }
    return parent::setCustomSeverityMap($map);
  }

  public function getLintSeverityMap() {
    return array();
  }

  public function getLintNameMap() {
    return array();
  }

  /**
   * Determines what executables and lint paths to use.  Between platforms
   * this also changes whether the lint engine is run under .NET or Mono.  It
   * also ensures that all of the required binaries are available for the lint
   * to run successfully.
   *
   * @return void
   */
  private function loadEnvironment() {
    if ($this->loaded) {
      return;
    }

    // Determine runtime engine (.NET or Mono).
    if (phutil_is_windows()) {
      $this->runtimeEngine = "";
    } else if (Filesystem::binaryExists("mono")) {
      $this->runtimeEngine = "mono ";
    } else {
      throw new Exception("Unable to find Mono and you are not on Windows!");
    }

    // Determine cslint path.
    $cslint = $this->cslintHintPath;
    if ($cslint !== null && file_exists($cslint)) {
      $this->cslintEngine = Filesystem::resolvePath($cslint);
    } else if (Filesystem::binaryExists("cslint.exe")) {
      $this->cslintEngine = "cslint.exe";
    } else {
      throw new Exception("Unable to locate cslint.");
    }

    // Determine cslint version.
    $ver_future = new ExecFuture(
      "%C -v",
      $this->runtimeEngine.$this->cslintEngine);
    list($err, $stdout, $stderr) = $ver_future->resolve();
    if ($err !== 0) {
      throw new Exception(
        "You are running an old version of cslint.  Please ".
        "upgrade to version ".self::SUPPORTED_VERSION.".");
    }
    $ver = (int)$stdout;
    if ($ver < self::SUPPORTED_VERSION) {
      throw new Exception(
        "You are running an old version of cslint.  Please ".
        "upgrade to version ".self::SUPPORTED_VERSION.".");
    } elseif ($ver > self::SUPPORTED_VERSION) {
      throw new Exception(
        "Arcanist does not support this version of cslint (it is ".
        "newer).  You can try upgrading Arcanist with `arc upgrade`.");
    }

    $this->loaded = true;
  }

  public function lintPath($path) {
  }

  public function willLintPaths(array $paths) {
    $this->loadEnvironment();

    $futures = array();

    // Bulk linting up into futures, where the number of files
    // is based on how long the command is.
    $current_paths = array();
    foreach ($paths as $path) {
      // If the current paths for the command, plus the next path
      // is greater than 6000 characters (less than the Windows
      // command line limit), then finalize this future and add it.
      $total = 0;
      foreach ($current_paths as $current_path) {
        $total += strlen($current_path) + 3; // Quotes and space.
      }
      if ($total + strlen($path) > 6000) {
        // %s won't pass through the JSON correctly
        // under Windows.  This is probably because not only
        // does the JSON have quotation marks in the content,
        // but because there'll be a lot of escaping and
        // double escaping because the JSON also contains
        // regular expressions.  cslint supports passing the
        // settings JSON through base64-encoded to mitigate
        // this issue.
        $futures[] = new ExecFuture(
          "%C --settings-base64=%s -r=. %Ls",
          $this->runtimeEngine.$this->cslintEngine,
          base64_encode(json_encode($this->discoveryMap)),
          $current_paths);
        $current_paths = array();
      }

      // Append the path to the current paths array.
      $current_paths[] = $this->getEngine()->getFilePathOnDisk($path);
    }

    // If we still have paths left in current paths, then we need to create
    // a future for those too.
    if (count($current_paths) > 0) {
      $futures[] = new ExecFuture(
        "%C --settings-base64=%s -r=. %Ls",
        $this->runtimeEngine.$this->cslintEngine,
        base64_encode(json_encode($this->discoveryMap)),
        $current_paths);
      $current_paths = array();
    }

    $this->futures = $futures;
  }

  public function didRunLinters() {
    if ($this->futures) {
      foreach (Futures($this->futures)->limit(8) as $future) {
        $this->resolveFuture($future);
      }
    }
  }

  protected function resolveFuture(Future $future) {
    list($stdout) = $future->resolvex();
    $all_results = json_decode($stdout);
    foreach ($all_results as $results) {
      if ($results === null || $results->Issues === null) {
        return;
      }
      foreach ($results->Issues as $issue) {
        $message = new ArcanistLintMessage();
        $message->setPath($results->FileName);
        $message->setLine($issue->LineNumber);
        $message->setCode($issue->Index->Code);
        $message->setName($issue->Index->Name);
        $message->setChar($issue->Column);
        $message->setOriginalText($issue->OriginalText);
        $message->setReplacementText($issue->ReplacementText);
        $message->setDescription(
          vsprintf($issue->Index->Message, $issue->Parameters));
        $severity = ArcanistLintSeverity::SEVERITY_ADVICE;
        switch ($issue->Index->Severity) {
          case 0:
            $severity = ArcanistLintSeverity::SEVERITY_ADVICE;
            break;
          case 1:
            $severity = ArcanistLintSeverity::SEVERITY_AUTOFIX;
            break;
          case 2:
            $severity = ArcanistLintSeverity::SEVERITY_WARNING;
            break;
          case 3:
            $severity = ArcanistLintSeverity::SEVERITY_ERROR;
            break;
          case 4:
            $severity = ArcanistLintSeverity::SEVERITY_DISABLED;
            break;
        }
        $severity_override = $this->getLintMessageSeverity($issue->Index->Code);
        if ($severity_override !== null) {
          $severity = $severity_override;
        }
        $message->setSeverity($severity);
        $this->addLintMessage($message);
      }
    }
  }

  protected function getDefaultMessageSeverity($code) {
    return null;
  }

}
