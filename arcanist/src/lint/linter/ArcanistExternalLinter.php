<?php

/**
 * Base class for linters which operate by invoking an external program and
 * parsing results.
 *
 * @task bin      Interpreters, Binaries and Flags
 * @task parse    Parsing Linter Output
 * @task exec     Executing the Linter
 */
abstract class ArcanistExternalLinter extends ArcanistFutureLinter {

  private $bin;
  private $interpreter;
  private $flags;


/* -(  Interpreters, Binaries and Flags  )----------------------------------- */


  /**
   * Return the default binary name or binary path where the external linter
   * lives. This can either be a binary name which is expected to be installed
   * in PATH (like "jshint"), or a relative path from the project root
   * (like "resources/support/bin/linter") or an absolute path.
   *
   * If the binary needs an interpreter (like "python" or "node"), you should
   * also override @{method:shouldUseInterpreter} and provide the interpreter
   * in @{method:getDefaultInterpreter}.
   *
   * @return string Default binary to execute.
   * @task bin
   */
  abstract public function getDefaultBinary();


  /**
   * Return a human-readable string describing how to install the linter. This
   * is normally something like "Install such-and-such by running `npm install
   * -g such-and-such`.", but will differ from linter to linter.
   *
   * @return string Human readable install instructions
   * @task bin
   */
  abstract public function getInstallInstructions();


  /**
   * Return true to continue when the external linter exits with an error code.
   * By default, linters which exit with an error code are assumed to have
   * failed. However, some linters exit with a specific code to indicate that
   * lint messages were detected.
   *
   * If the linter sometimes raises errors during normal operation, override
   * this method and return true so execution continues when it exits with
   * a nonzero status.
   *
   * @param bool  Return true to continue on nonzero error code.
   * @task bin
   */
  public function shouldExpectCommandErrors() {
    return false;
  }


  /**
   * Return true to indicate that the external linter can read input from
   * stdin, rather than requiring a file. If this mode is supported, it is
   * slightly more flexible and may perform better, and is thus preferable.
   *
   * To send data over stdin instead of via a command line parameter, override
   * this method and return true. If the linter also needs a command line
   * flag (like `--stdin` or `-`), override
   * @{method:getReadDataFromStdinFilename} to provide it.
   *
   * For example, linters are normally invoked something like this:
   *
   *   $ linter file.js
   *
   * If you override this method, invocation will be more similar to this:
   *
   *   $ linter < file.js
   *
   * If you additionally override @{method:getReadDataFromStdinFilename} to
   * return `"-"`, invocation will be similar to this:
   *
   *   $ linter - < file.js
   *
   * @return bool True to send data over stdin.
   * @task bin
   */
  public function supportsReadDataFromStdin() {
    return false;
  }


  /**
   * If the linter can read data over stdin, override
   * @{method:supportsReadDataFromStdin} and then optionally override this
   * method to provide any required arguments (like `-` or `--stdin`). See
   * that method for discussion.
   *
   * @return string|null  Additional arguments required by the linter when
   *                      operating in stdin mode.
   * @task bin
   */
  public function getReadDataFromStdinFilename() {
    return null;
  }


  /**
   * Provide mandatory, non-overridable flags to the linter. Generally these
   * are format flags, like `--format=xml`, which must always be given for
   * the output to be usable.
   *
   * Flags which are not mandatory should be provided in
   * @{method:getDefaultFlags} instead.
   *
   * @return string|null  Mandatory flags, like `"--format=xml"`.
   * @task bin
   */
  protected function getMandatoryFlags() {
    return null;
  }


  /**
   * Provide default, overridable flags to the linter. Generally these are
   * configuration flags which affect behavior but aren't critical. Flags
   * which are required should be provided in @{method:getMandatoryFlags}
   * instead.
   *
   * Default flags can be overridden with @{method:setFlags}.
   *
   * @return string|null  Overridable default flags.
   * @task bin
   */
  protected function getDefaultFlags() {
    return null;
  }


  /**
   * Override default flags with custom flags. If not overridden, flags provided
   * by @{method:getDefaultFlags} are used.
   *
   * @param string New flags.
   * @return this
   * @task bin
   */
  final public function setFlags($flags) {
    $this->flags = $flags;
    return $this;
  }


  /**
   * Return the binary or script to execute. This method synthesizes defaults
   * and configuration. You can override the binary with @{method:setBinary}.
   *
   * @return string Binary to execute.
   * @task bin
   */
  final public function getBinary() {
    return coalesce($this->bin, $this->getDefaultBinary());
  }


  /**
   * Override the default binary with a new one.
   *
   * @param string  New binary.
   * @return this
   * @task bin
   */
  final public function setBinary($bin) {
    $this->bin = $bin;
    return $this;
  }


  /**
   * Return true if this linter should use an interpreter (like "python" or
   * "node") in addition to the script.
   *
   * After overriding this method to return `true`, override
   * @{method:getDefaultInterpreter} to set a default.
   *
   * @return bool True to use an interpreter.
   * @task bin
   */
  public function shouldUseInterpreter() {
    return false;
  }


  /**
   * Return the default interpreter, like "python" or "node". This method is
   * only invoked if @{method:shouldUseInterpreter} has been overridden to
   * return `true`.
   *
   * @return string Default interpreter.
   * @task bin
   */
  public function getDefaultInterpreter() {
    throw new Exception("Incomplete implementation!");
  }


  /**
   * Get the effective interpreter. This method synthesizes configuration and
   * defaults.
   *
   * @return string Effective interpreter.
   * @task bin
   */
  final public function getInterpreter() {
    return coalesce($this->interpreter, $this->getDefaultInterpreter());
  }


  /**
   * Set the interpreter, overriding any default.
   *
   * @param string New interpreter.
   * @return this
   * @task bin
   */
  final public function setInterpreter($interpreter) {
    $this->interpreter = $interpreter;
    return $this;
  }


/* -(  Parsing Linter Output  )---------------------------------------------- */


  /**
   * Parse the output of the external lint program into objects of class
   * @{class:ArcanistLintMessage} which `arc` can consume. Generally, this
   * means examining the output and converting each warning or error into a
   * message.
   *
   * If parsing fails, returning `false` will cause the caller to throw an
   * appropriate exception. (You can also throw a more specific exception if
   * you're able to detect a more specific condition.) Otherwise, return a list
   * of messages.
   *
   * @param  string   Path to the file being linted.
   * @param  int      Exit code of the linter.
   * @param  string   Stdout of the linter.
   * @param  string   Stderr of the linter.
   * @return list<ArcanistLintMessage>|false  List of lint messages, or false
   *                                          to indicate parser failure.
   * @task parse
   */
  abstract protected function parseLinterOutput($path, $err, $stdout, $stderr);


/* -(  Executing the Linter  )----------------------------------------------- */


  /**
   * Check that the binary and interpreter (if applicable) exist, and throw
   * an exception with a message about how to install them if they do not.
   *
   * @return void
   */
  final public function checkBinaryConfiguration() {
    $interpreter = null;
    if ($this->shouldUseInterpreter()) {
      $interpreter = $this->getInterpreter();
    }

    $binary = $this->getBinary();

    // NOTE: If we have an interpreter, we don't require the script to be
    // executable (so we just check that the path exists). Otherwise, the
    // binary must be executable.

    if ($interpreter) {
      if (!Filesystem::binaryExists($interpreter)) {
        throw new ArcanistUsageException(
          pht(
            'Unable to locate interpreter "%s" to run linter %s. You may '.
            'need to install the intepreter, or adjust your linter '.
            'configuration.'.
            "\nTO INSTALL: %s",
            $interpreter,
            get_class($this),
            $this->getInstallInstructions()));
      }
      if (!Filesystem::pathExists($binary)) {
        throw new ArcanistUsageException(
          pht(
            'Unable to locate script "%s" to run linter %s. You may need '.
            'to install the script, or adjust your linter configuration. '.
            "\nTO INSTALL: %s",
            $binary,
            get_class($this),
            $this->getInstallInstructions()));
      }
    } else {
      if (!Filesystem::binaryExists($binary)) {
        throw new ArcanistUsageException(
          pht(
            'Unable to locate binary "%s" to run linter %s. You may need '.
            'to install the binary, or adjust your linter configuration. '.
            "\nTO INSTALL: %s",
            $binary,
            get_class($this),
            $this->getInstallInstructions()));
      }
    }
  }


  /**
   * Get the composed executable command, including the interpreter and binary
   * but without flags or paths. This can be used to execute `--version`
   * commands.
   *
   * @return string Command to execute the raw linter.
   * @task exec
   */
  protected function getExecutableCommand() {
    $this->checkBinaryConfiguration();

    $interpreter = null;
    if ($this->shouldUseInterpreter()) {
      $interpreter = $this->getInterpreter();
    }

    $binary = $this->getBinary();

    if ($interpreter) {
      $bin = csprintf('%s %s', $interpreter, $binary);
    } else {
      $bin = csprintf('%s', $binary);
    }

    return $bin;
  }


  /**
   * Get the composed flags for the executable, including both mandatory and
   * configured flags.
   *
   * @return string Composed flags.
   * @task exec
   */
  protected function getCommandFlags() {
    return csprintf(
      '%C %C',
      $this->getMandatoryFlags(),
      coalesce($this->flags, $this->getDefaultFlags()));
  }


  protected function buildFutures(array $paths) {
    $executable = $this->getExecutableCommand();

    $bin = csprintf('%C %C', $executable, $this->getCommandFlags());

    $futures = array();
    foreach ($paths as $path) {
      if ($this->supportsReadDataFromStdin()) {
        $future = new ExecFuture(
          '%C %C',
          $bin,
          $this->getReadDataFromStdinFilename());
        $future->write($this->getEngine()->loadData($path));
      } else {
        // TODO: In commit hook mode, we need to do more handling here.
        $disk_path = $this->getEngine()->getFilePathOnDisk($path);
        $future = new ExecFuture('%C %s', $bin, $disk_path);
      }

      $futures[$path] = $future;
    }

    return $futures;
  }

  protected function resolveFuture($path, Future $future) {
    list($err, $stdout, $stderr) = $future->resolve();
    if ($err && !$this->shouldExpectCommandErrors()) {
      $future->resolvex();
    }

    $messages = $this->parseLinterOutput($path, $err, $stdout, $stderr);

    if ($messages === false) {
      if ($err) {
        $future->resolvex();
      } else {
        throw new Exception(
          "Linter failed to parse output!\n\n{$stdout}\n\n{$stderr}");
      }
    }

    foreach ($messages as $message) {
      $this->addLintMessage($message);
    }
  }


  public function getLinterConfigurationOptions() {
    $options = array(
      'bin' => 'optional string | list<string>',
      'flags' => 'optional string',
    );

    if ($this->shouldUseInterpreter()) {
      $options['interpreter'] = 'optional string | list<string>';
    }

    return $options + parent::getLinterConfigurationOptions();
  }

  public function setLinterConfigurationValue($key, $value) {
    switch ($key) {
      case 'interpreter':
        $working_copy = $this->getEngine()->getWorkingCopy();
        $root = $working_copy->getProjectRoot();

        foreach ((array)$value as $path) {
          if (Filesystem::binaryExists($path)) {
            $this->setInterpreter($path);
            return;
          }

          $path = Filesystem::resolvePath($path, $root);

          if (Filesystem::binaryExists($path)) {
            $this->setInterpreter($path);
            return;
          }
        }

        throw new Exception(
          pht('None of the configured interpreters can be located.'));
      case 'bin':
        $is_script = $this->shouldUseInterpreter();

        $working_copy = $this->getEngine()->getWorkingCopy();
        $root = $working_copy->getProjectRoot();

        foreach ((array)$value as $path) {
          if (!$is_script && Filesystem::binaryExists($path)) {
            $this->setBinary($path);
            return;
          }

          $path = Filesystem::resolvePath($path, $root);
          if ((!$is_script && Filesystem::binaryExists($path)) ||
              ($is_script && Filesystem::pathExists($path))) {
            $this->setBinary($path);
            return;
          }
        }

        throw new Exception(
          pht('None of the configured binaries can be located.'));
      case 'flags':
        if (strlen($value)) {
          $this->setFlags($value);
        }
        return;
    }

    return parent::setLinterConfigurationValue($key, $value);
  }


  /**
   * Map a configuration lint code to an `arc` lint code. Primarily, this is
   * intended for validation, but can also be used to normalize case or
   * otherwise be more permissive in accepted inputs.
   *
   * If the code is not recognized, you should throw an exception.
   *
   * @param string  Code specified in configuration.
   * @return string  Normalized code to use in severity map.
   */
  protected function getLintCodeFromLinterConfigurationKey($code) {
    return $code;
  }

}
