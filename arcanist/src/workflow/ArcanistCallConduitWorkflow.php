<?php

/**
 * Provides command-line access to the Conduit API.
 *
 * @group workflow
 */
final class ArcanistCallConduitWorkflow extends ArcanistBaseWorkflow {

  public function getWorkflowName() {
    return 'call-conduit';
  }

  public function getCommandSynopses() {
    return phutil_console_format(<<<EOTEXT
      **call-conduit** __method__
EOTEXT
      );
  }

  public function getCommandHelp() {
    return phutil_console_format(<<<EOTEXT
          Supports: http, https
          Allows you to make a raw Conduit method call:

            - Run this command from a working directory.
            - Call parameters are REQUIRED and read as a JSON blob from stdin.
            - Results are written to stdout as a JSON blob.

          This workflow is primarily useful for writing scripts which integrate
          with Phabricator. Examples:

            $ echo '{}' | arc call-conduit conduit.ping
            $ echo '{"phid":"PHID-FILE-xxxx"}' | arc call-conduit file.download
EOTEXT
      );
  }

  public function getArguments() {
    return array(
      '*' => 'method',
    );
  }

  public function shouldShellComplete() {
    return false;
  }

  public function requiresConduit() {
    return true;
  }

  public function requiresAuthentication() {
    return true;
  }

  public function run() {
    $method = $this->getArgument('method', array());
    if (count($method) !== 1) {
      throw new ArcanistUsageException(
        "Provide exactly one Conduit method name.");
    }
    $method = reset($method);

    $params = @file_get_contents('php://stdin');
    $params = json_decode($params, true);
    if (!is_array($params)) {
      throw new ArcanistUsageException(
        "Provide method parameters on stdin as a JSON blob.");
    }

    $error = null;
    $error_message = null;
    try {
      $result = $this->getConduit()->callMethodSynchronous(
        $method,
        $params);
    } catch (ConduitClientException $ex) {
      $error = $ex->getErrorCode();
      $error_message = $ex->getMessage();
      $result = null;
    }

    echo json_encode(array(
      'error'         => $error,
      'errorMessage'  => $error_message,
      'response'      => $result,
    ))."\n";

    return 0;
  }
}
