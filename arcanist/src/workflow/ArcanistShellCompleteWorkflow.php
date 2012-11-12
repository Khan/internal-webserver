<?php

/**
 * Powers shell-completion scripts.
 *
 * @group workflow
 */
final class ArcanistShellCompleteWorkflow extends ArcanistBaseWorkflow {

  public function getWorkflowName() {
    return 'shell-complete';
  }

  public function getCommandSynopses() {
    return phutil_console_format(<<<EOTEXT
      **shell-complete** __--current__ __N__ -- [__argv__]
EOTEXT
      );
  }

  public function getCommandHelp() {
    return phutil_console_format(<<<EOTEXT
          Supports: bash, etc.
          Implements shell completion. To use shell completion, source the
          appropriate script from 'resources/shell/' in your .shellrc.
EOTEXT
      );
  }

  public function getArguments() {
    return array(
      'current' => array(
        'help' => 'Current term in the argument list being completed.',
        'param' => 'cursor_position',
      ),
      '*' => 'argv',
    );
  }

  public function shouldShellComplete() {
    return false;
  }

  public function run() {

    $pos  = $this->getArgument('current');
    $argv = $this->getArgument('argv', array());
    $argc = count($argv);
    if ($pos === null) {
      $pos = $argc - 1;
    }

    // Determine which revision control system the working copy uses, so we
    // can filter out commands and flags which aren't supported. If we can't
    // figure it out, just return all flags/commands.
    $vcs = null;

    // We have to build our own because if we requiresWorkingCopy() we'll throw
    // if we aren't in a .arcconfig directory. We probably still can't do much,
    // but commands can raise more detailed errors.
    $working_copy = ArcanistWorkingCopyIdentity::newFromPath(getcwd());
    if ($working_copy->getProjectRoot()) {
      $repository_api = ArcanistRepositoryAPI::newAPIFromWorkingCopyIdentity(
        $working_copy);
      $vcs = $repository_api->getSourceControlSystemName();
    }

    $arc_config = $this->getArcanistConfiguration();

    if ($pos == 1) {
      $workflows = $arc_config->buildAllWorkflows();

      $complete = array();
      foreach ($workflows as $name => $workflow) {
        if (!$workflow->shouldShellComplete()) {
          continue;
        }

        $supported = $workflow->getSupportedRevisionControlSystems();

        $ok = (in_array('any', $supported)) ||
              (in_array($vcs, $supported));
        if (!$ok) {
          continue;
        }

        $complete[] = $name;
      }

      // Also permit autocompletion of "arc alias" commands.
      foreach (
        ArcanistAliasWorkflow::getAliases($working_copy) as $key => $value) {
        $complete[] = $key;
      }

      echo implode(' ', $complete)."\n";
      return 0;
    } else {
      $workflow = $arc_config->buildWorkflow($argv[1]);
      if (!$workflow) {
        list($new_command, $new_args) = ArcanistAliasWorkflow::resolveAliases(
          $argv[1],
          $arc_config,
          array_slice($argv, 2),
          $working_copy);
        if ($new_command) {
          $workflow = $arc_config->buildWorkflow($new_command);
        }
        if (!$workflow) {
          return 1;
        } else {
          $argv = array_merge(
            array($argv[0]),
            array($new_command),
            $new_args);
        }
      }

      $arguments = $workflow->getArguments();

      $prev = idx($argv, $pos - 1, null);
      if (!strncmp($prev, '--', 2)) {
        $prev = substr($prev, 2);
      } else {
        $prev = null;
      }

      if ($prev !== null &&
          isset($arguments[$prev]) &&
          isset($arguments[$prev]['param'])) {

        $type = idx($arguments[$prev], 'paramtype');
        switch ($type) {
          case 'file':
            echo "FILE\n";
            break;
          case 'complete':
            echo implode(' ', $workflow->getShellCompletions($argv))."\n";
            break;
          default:
            echo "ARGUMENT\n";
            break;
        }
        return 0;
      } else {

        $output = array();
        foreach ($arguments as $argument => $spec) {
          if ($argument == '*') {
            continue;
          }
          if ($vcs &&
              isset($spec['supports']) &&
              !in_array($vcs, $spec['supports'])) {
            continue;
          }
          $output[] = '--'.$argument;
        }

        $cur = idx($argv, $pos, '');
        $any_match = false;

        if (strlen($cur)) {
          foreach ($output as $possible) {
            if (!strncmp($possible, $cur, strlen($cur))) {
              $any_match = true;
            }
          }
        }

        if (!$any_match && isset($arguments['*'])) {
          // TODO: This is mega hacktown but something else probably breaks
          // if we use a rich argument specification; fix it when we move to
          // PhutilArgumentParser since everything will need to be tested then
          // anyway.
          if ($arguments['*'] == 'branch' && isset($repository_api)) {
            $branches = $repository_api->getAllBranches();
            $branches = ipull($branches, 'name');
            $output = $branches;
          } else {
            $output = array("FILE");
          }
        }

        echo implode(' ', $output)."\n";

        return 0;
      }
    }
  }
}
