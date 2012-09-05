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
 * Parser for command-line arguments for scripts. Like similar parsers, this
 * class allows you to specify, validate, and render help for command-line
 * arguments. For example:
 *
 *   name=create_dog.php
 *   $args = new PhutilArgumentParser($argv);
 *   $args->setTagline('make an new dog')
 *   $args->setSynopsis(<<<EOHELP
 *   **dog** [--big] [--name __name__]
 *   Create a new dog. How does it work? Who knows.
 *   EOHELP
 *   );
 *   $args->parse(
 *     array(
 *       array(
 *         'name'     => 'name',
 *         'param'    => 'dogname',
 *         'default'  => 'Rover',
 *         'help'     => 'Set the dog\'s name. By default, the dog will be '.
 *                       'named "Rover".',
 *       ),
 *       array(
 *         'name'     => 'big',
 *         'short'    => 'b',
 *         'help'     => 'If set, create a large dog.',
 *       ),
 *     ));
 *
 *   $dog_name = $args->getArg('name');
 *   $dog_size = $args->getArg('big') ? 'big' : 'small';
 *
 *   // ... etc ...
 *
 * (For detailed documentation on supported keys in argument specifications,
 * see @{class:PhutilArgumentSpecification}.)
 *
 * This will handle argument parsing, and generate appropriate usage help if
 * the user provides an unsupported flag. @{class:PhutilArgumentParser} also
 * supports some builtin "standard" arguments:
 *
 *   $args->parseStandardArguments();
 *
 * See @{method:parseStandardArguments} for details. Notably, this includes
 * a "--help" flag, and an "--xprofile" flag for profiling command-line scripts.
 *
 * Normally, when the parser encounters an unknown flag, it will exit with
 * an error. However, you can use @{method:parsePartial} to consume only a
 * set of flags:
 *
 *   $args->parsePartial($spec_list);
 *
 * This allows you to parse some flags before making decisions about other
 * parsing, or share some flags across scripts. The builtin standard arguments
 * are implemented in this way.
 *
 * There is also builtin support for "workflows", which allow you to build a
 * script that operates in several modes (e.g., by accepting commands like
 * `install`, `upgrade`, etc), like `arc` does. For detailed documentation on
 * workflows, see @{class:PhutilArgumentWorkflow}.
 *
 * @task parse    Parsing Arguments
 * @task read     Reading Arguments
 * @task help     Command Help
 * @task internal Internals
 *
 * @group console
 */
final class PhutilArgumentParser {

  private $bin;
  private $argv;
  private $specs = array();
  private $results = array();
  private $parsed;

  private $tagline;
  private $synopsis;
  private $workflows;
  private $showHelp;

  const PARSE_ERROR_CODE = 77;


/* -(  Parsing Arguments  )-------------------------------------------------- */


  /**
   * Build a new parser. Generally, you start a script with:
   *
   *   $args = new PhutilArgumentParser($argv);
   *
   * @param list  Argument vector to parse, generally the $argv global.
   * @task parse
   */
  public function __construct(array $argv) {
    $this->bin = $argv[0];
    $this->argv = array_slice($argv, 1);
  }


  /**
   * Parse and consume a list of arguments, removing them from the argument
   * vector but leaving unparsed arguments for later consumption. You can
   * retreive unconsumed arguments directly with
   * @{method:getUnconsumedArgumentVector}. Doing a partial parse can make it
   * easier to share common flags across scripts or workflows.
   *
   * @param   list  List of argument specs, see
   *                @{class:PhutilArgumentSpecification}.
   * @return  this
   * @task parse
   */
  public function parsePartial(array $specs) {
    $specs = PhutilArgumentSpecification::newSpecsFromList($specs);
    $this->mergeSpecs($specs);

    $specs_by_name  = mpull($specs, null, 'getName');
    $specs_by_short = mpull($specs, null, 'getShortAlias');
    unset($specs_by_short[null]);

    $argv = $this->argv;
    $len = count($argv);
    for ($ii = 0; $ii < $len; $ii++) {
      $arg = $argv[$ii];
      $map = null;
      if ($arg == '--') {
        // This indicates "end of flags".
        break;
      } else if ($arg == '-') {
        // This is a normal argument (e.g., stdin).
        continue;
      } else if (!strncmp('--', $arg, 2)) {
        $pre = '--';
        $arg = substr($arg, 2);
        $map = $specs_by_name;
      } else if (!strncmp('-', $arg, 1) && strlen($arg) > 1) {
        $pre = '-';
        $arg = substr($arg, 1);
        $map = $specs_by_short;
      }

      if ($map) {
        $val = null;
        $parts = explode('=', $arg, 2);
        if (count($parts) == 2) {
          list($arg, $val) = $parts;
        }

        if (isset($map[$arg])) {
          $spec = $map[$arg];
          unset($argv[$ii]);

          $param_name = $spec->getParamName();
          if ($val !== null) {
            if ($param_name === null) {
              throw new PhutilArgumentUsageException(
                "Argument '{$pre}{$arg}' does not take a parameter.");
            }
          } else {
            if ($param_name !== null) {
              if ($ii + 1 < $len) {
                $val = $argv[$ii + 1];
                unset($argv[$ii + 1]);
                $ii++;
              } else {
                throw new PhutilArgumentUsageException(
                  "Argument '{$pre}{$arg}' requires a parameter.");
              }
            } else {
              $val = true;
            }
          }

          if (!$spec->getRepeatable()) {
            if (array_key_exists($spec->getName(), $this->results)) {
              throw new PhutilArgumentUsageException(
                "Argument '{$pre}{$arg}' was provided twice.");
            }
          }

          $conflicts = $spec->getConflicts();
          foreach ($conflicts as $conflict => $reason) {
            if (array_key_exists($conflict, $this->results)) {

              if (!is_string($reason) || !strlen($reason)) {
                $reason = '.';
              } else {
                $reason = ': '.$reason.'.';
              }

              throw new PhutilArgumentUsageException(
                "Argument '{$pre}{$arg}' conflicts with argument ".
                "'--{$conflict}'{$reason}");
            }
          }

          if ($spec->getRepeatable()) {
            if ($spec->getParamName() === null) {
              if (empty($this->results[$spec->getName()])) {
                $this->results[$spec->getName()] = 0;
              }
              $this->results[$spec->getName()]++;
            } else {
              $this->results[$spec->getName()][] = $val;
            }
          } else {
            $this->results[$spec->getName()] = $val;
          }
        }
      }
    }

    foreach ($specs as $spec) {
      if ($spec->getWildcard()) {
        $this->results[$spec->getName()] = $this->filterWildcardArgv($argv);
        $argv = array();
        break;
      }
    }

    $this->argv = array_values($argv);
    return $this;
  }


  /**
   * Parse and consume a list of arguments, throwing an exception if there is
   * anything left unconsumed. This is like @{method:parsePartial}, but raises
   * a {class:PhutilArgumentUsageException} if there are leftovers.
   *
   * Normally, you would call @{method:parse} instead, which emits a
   * user-friendly error. You can also use @{method:printUsageException} to
   * render the exception in a user-friendly way.
   *
   * @param   list  List of argument specs, see
   *                @{class:PhutilArgumentSpecification}.
   * @return  this
   * @task parse
   */
  public function parseFull(array $specs) {
    $this->parsePartial($specs);

    if (count($this->argv)) {
      $arg = head($this->argv);
      throw new PhutilArgumentUsageException(
        "Unrecognized argument '{$arg}'.");
    }

    if ($this->showHelp) {
      $this->printHelpAndExit();
    }

    return $this;
  }


  /**
   * Parse and consume a list of arguments, raising a user-friendly error if
   * anything remains. See also @{method:parseFull} and @{method:parsePartial}.
   *
   * @param   list  List of argument specs, see
   *                @{class:PhutilArgumentSpecification}.
   * @return  this
   * @task parse
   */
  public function parse(array $specs) {
    try {
      return $this->parseFull($specs);
    } catch (PhutilArgumentUsageException $ex) {
      $this->printUsageException($ex);
      exit(self::PARSE_ERROR_CODE);
    }
  }


  /**
   * Parse and execute workflows, raising a user-friendly error if anything
   * remains. See also @{method:parseWorkflowsFull}.
   *
   * See @{class:PhutilArgumentWorkflow} for details on using workflows.
   *
   * @param   list  List of argument specs, see
   *                @{class:PhutilArgumentSpecification}.
   * @return  this
   * @task parse
   */
  public function parseWorkflows(array $workflows) {
    try {
      return $this->parseWorkflowsFull($workflows);
    } catch (PhutilArgumentUsageException $ex) {
      $this->printUsageException($ex);
      exit(self::PARSE_ERROR_CODE);
    }
  }


  /**
   * Select a workflow. For commands that may operate in several modes, like
   * `arc`, the modes can be split into "workflows". Each workflow specifies
   * the arguments it accepts. This method takes a list of workflows, selects
   * the chosen workflow, parses its arguments, and either executes it (if it
   * is executable) or returns it for handling.
   *
   * See @{class:PhutilArgumentWorkflow} for details on using workflows.
   *
   * @param list List of @{class:PhutilArgumentWorkflow}s.
   * @return PhutilArgumentWorkflow|no  Returns the chosen workflow if it is
   *                                    not executable, or executes it and
   *                                    exits with a return code if it is.
   * @task parse
   */
  public function parseWorkflowsFull(array $workflows) {
    assert_instances_of($workflows, 'PhutilArgumentWorkflow');

    foreach ($workflows as $workflow) {
      $name = $workflow->getName();

      if ($name === null) {
        throw new PhutilArgumentSpecificationException(
          "Workflow has no name!");
      }

      if (isset($this->workflows[$name])) {
        throw new PhutilArgumentSpecificationException(
          "Two workflows with name '{$name}!");
      }

      $this->workflows[$name] = $workflow;
    }

    $argv = $this->argv;
    if (empty($argv)) {
      // TODO: this is kind of hacky / magical.
      if (isset($this->workflows['help'])) {
        $argv = array('help');
      } else {
        throw new PhutilArgumentUsageException(
          "No workflow selected.");
      }
    }

    $flow = array_shift($argv);
    $flow = strtolower($flow);

    if (empty($this->workflows[$flow])) {
      throw new PhutilArgumentUsageException(
        "Invalid workflow '{$flow}'.");
    }

    $workflow = $this->workflows[$flow];

    if ($this->showHelp) {
      $this->printHelpAndExit();
    }

    $this->argv = array_values($argv);
    $this->parse($workflow->getArguments());

    if ($workflow->isExecutable()) {
      $err = $workflow->execute($this);
      exit($err);
    } else {
      return $workflow;
    }
  }


  /**
   * Parse "standard" arguments and apply their effects:
   *
   *    --trace             Enable service call tracing.
   *    --no-ansi           Disable ANSI color/style sequences.
   *    --xprofile <file>   Write out an XHProf profile.
   *    --help              Show help.
   *
   * @return this
   *
   * @phutil-external-symbol function xhprof_enable
   */
  public function parseStandardArguments() {
    try {
      $this->parsePartial(
        array(
          array(
            'name'  => 'trace',
            'help'  => 'Trace command execution and show service calls.',
          ),
          array(
            'name'  => 'no-ansi',
            'help'  => 'Disable ANSI terminal codes, printing plain text with '.
                       'no color or style.',
            'conflicts' => array(
              'ansi' => null,
            ),
          ),
          array(
            'name'  => 'ansi',
            'help'  => "Use formatting even in environments which probably ".
                       "don't support it.",
          ),
          array(
            'name'  => 'xprofile',
            'param' => 'profile',
            'help'  => 'Profile script execution and write results to a file.',
          ),
          array(
            'name'  => 'help',
            'short' => 'h',
            'help'  => 'Show this help.',
          ),
          array(
            'name'  => 'recon',
            'help'  => 'Start in remote console mode.',
          ),
        ));
    } catch (PhutilArgumentUsageException $ex) {
      $this->printUsageException($ex);
      exit(self::PARSE_ERROR_CODE);
    }

    if ($this->getArg('trace')) {
      PhutilServiceProfiler::installEchoListener();
    }

    if ($this->getArg('no-ansi')) {
      PhutilConsoleFormatter::disableANSI(true);
    }

    if ($this->getArg('ansi')) {
      PhutilConsoleFormatter::disableANSI(false);
    }

    if ($this->getArg('help')) {
      $this->showHelp = true;
    }

    $xprofile = $this->getArg('xprofile');
    if ($xprofile) {
      if (!function_exists('xhprof_enable')) {
        throw new Exception("To use '--xprofile', you must install XHProf.");
      }

      xhprof_enable(0);
      register_shutdown_function(array($this, 'shutdownProfiler'));
    }

    $recon = $this->getArg('recon');
    if ($recon) {
      $remote_console = PhutilConsole::newRemoteConsole();
      $remote_console->beginRedirectOut();
      PhutilConsole::setConsole($remote_console);
    } else if ($this->getArg('trace')) {
      $server = new PhutilConsoleServer();
      $server->setEnableLog(true);
      $console = PhutilConsole::newConsoleForServer($server);
      PhutilConsole::setConsole($console);
    }

    return $this;
  }


/* -(  Reading Arguments  )-------------------------------------------------- */


  public function getArg($name) {
    if (empty($this->specs[$name])) {
      throw new PhutilArgumentSpecificationException(
        "No specification exists for argument '{$name}'!");
    }

    if (idx($this->results, $name) !== null) {
      return $this->results[$name];
    }

    return $this->specs[$name]->getDefault();
  }

  public function getUnconsumedArgumentVector() {
    return $this->argv;
  }


/* -(  Command Help  )------------------------------------------------------- */


  public function setSynopsis($synopsis) {
    $this->synopsis = $synopsis;
    return $this;
  }

  public function setTagline($tagline) {
    $this->tagline = $tagline;
    return $this;
  }

  public function printHelpAndExit() {
    echo $this->renderHelp();
    exit(self::PARSE_ERROR_CODE);
  }

  public function renderHelp() {
    $out = array();

    if ($this->bin) {
      $out[] = $this->format('**NAME**');
      $name = $this->indent(6, '**%s**', basename($this->bin));
      if ($this->tagline) {
        $name .= $this->format(' - '.$this->tagline);
      }
      $out[] = $name;
      $out[] = null;
    }

    if ($this->synopsis) {
      $out[] = $this->format('**SYNOPSIS**');
      $out[] = $this->indent(6, $this->synopsis);
      $out[] = null;
    }

    if ($this->workflows) {
      $has_help = false;
      $out[] = $this->format('**WORKFLOWS**');
      $out[] = null;
      $flows = $this->workflows;
      ksort($flows);
      foreach ($flows as $workflow) {
        if ($workflow->getName() == 'help') {
          $has_help = true;
        }
        $out[] = $this->renderWorkflowHelp(
          $workflow->getName(),
          $show_flags = false);
      }
      if ($has_help) {
        $out[] = $this->indent(
          6,
          "Use **help** __command__ for a detailed command reference.\n");
      }
    }

    $specs = $this->renderArgumentSpecs($this->specs);
    if ($specs) {
      $out[] = $this->format('**OPTION REFERENCE**');
      $out[] = null;
      $out[] = $specs;
    }

    $out[] = null;

    return implode("\n", $out);
  }

  public function renderWorkflowHelp(
    $workflow_name,
    $show_flags = false) {

    $out = array();

    $workflow = idx($this->workflows, strtolower($workflow_name));
    if (!$workflow) {
      $out[] = $this->indent(
        6,
        "There is no **{$workflow_name}** workflow.");
    } else {
      $out[] = $this->indent(6, $workflow->getExamples());
      $out[] = $this->indent(6, $workflow->getSynopsis());
      if ($show_flags) {
        $specs = $this->renderArgumentSpecs($workflow->getArguments());
        if ($specs) {
          $out[] = null;
          $out[] = $specs;
        }
      }
    }

    $out[] = null;

    return implode("\n", $out);
  }

  public function printUsageException(PhutilArgumentUsageException $ex) {
    file_put_contents(
      'php://stderr',
      $this->format('**Usage Exception:** '.$ex->getMessage()."\n"));
  }


/* -(  Internals  )---------------------------------------------------------- */


  private function filterWildcardArgv(array $argv) {
    foreach ($argv as $key => $value) {
      if ($value == '--') {
        unset($argv[$key]);
        break;
      } else if (!strncmp($value, '-', 1) && strlen($value) > 1) {
        throw new PhutilArgumentUsageException(
          "Argument '{$value}' is unrecognized. Use '--' to indicate the ".
          "end of flags.");
      }
    }
    return array_values($argv);
  }

  private function mergeSpecs(array $specs) {

    $short_map = mpull($this->specs, null, 'getShortAlias');
    unset($short_map[null]);

    $wildcard = null;
    foreach ($this->specs as $spec) {
      if ($spec->getWildcard()) {
        $wildcard = $spec;
        break;
      }
    }

    foreach ($specs as $spec) {
      $spec->validate();
      $name = $spec->getName();

      if (isset($this->specs[$name])) {
        throw new PhutilArgumentSpecificationException(
          "Two argument specifications have the same name ('{$name}').");
      }

      $short = $spec->getShortAlias();
      if ($short) {
        if (isset($short_map[$short])) {
          throw new PhutilArgumentSpecificationException(
            "Two argument specifications have the same short alias ".
            "('{$short}').");
        }
        $short_map[$short] = $spec;
      }

      if ($spec->getWildcard()) {
        if ($wildcard) {
          throw new PhutilArgumentSpecificationException(
            "Two argument specifications are marked as wildcard arguments. ".
            "You can have a maximum of one wildcard argument.");
        } else {
          $wildcard = $spec;
        }
      }

      $this->specs[$name] = $spec;
    }

    foreach ($this->specs as $name => $spec) {
      foreach ($spec->getConflicts() as $conflict => $reason) {
        if (empty($this->specs[$conflict])) {
          throw new PhutilArgumentSpecificationException(
            "Argument '{$name}' conflicts with unspecified argument ".
            "'{$conflict}'.");
        }
        if ($conflict == $name) {
          throw new PhutilArgumentSpecificationException(
            "Argument '{$name}' conflicts with itself!");
        }
      }
    }

  }

  private function renderArgumentSpecs(array $specs) {
    foreach ($specs as $key => $spec) {
      if ($spec->getWildcard()) {
        unset($specs[$key]);
      }
    }

    $out = array();

    $specs = msort($specs, 'getName');
    foreach ($specs as $spec) {
      $name = $this->indent(6, '__--%s__', $spec->getName());
      $short = null;
      if ($spec->getShortAlias()) {
        $short = $this->format(', __-%s__', $spec->getShortAlias());
      }
      if ($spec->getParamName()) {
        $param = $this->format(' __%s__', $spec->getParamName());
        $name .= $param;
        if ($short) {
          $short .= $param;
        }
      }
      $out[] = $name.$short;
      $out[] = $this->indent(10, $spec->getHelp());
      $out[] = null;
    }
    return implode("\n", $out);
  }

  private function format($str /* , ... */) {
    $args = func_get_args();
    return call_user_func_array(
      'phutil_console_format',
      $args);
  }

  private function indent($level, $str /* , ... */) {
    $args = func_get_args();
    $args = array_slice($args, 1);
    $text = call_user_func_array(array($this, 'format'), $args);
    return phutil_console_wrap($text, $level);
  }

  /**
   * @phutil-external-symbol function xhprof_disable
   */
  public function shutdownProfiler() {
    $data = xhprof_disable();
    $data = serialize($data);
    Filesystem::writeFile($this->getArg('xprofile'), $data);
  }

}
