<?php

/**
 * Shows lint messages to the user.
 *
 * @group lint
 */
final class ArcanistLintLikeCompilerRenderer implements ArcanistLintRenderer {
  public function renderLintResult(ArcanistLintResult $result) {
    $lines = array();
    $messages = $result->getMessages();
    $path = $result->getPath();

    foreach ($messages as $message) {
      $severity = ArcanistLintSeverity::getStringForSeverity(
        $message->getSeverity());
      $line = $message->getLine();
      $code = $message->getCode();
      $description = $message->getDescription();
      $lines[] = sprintf(
        "%s:%d:%s (%s) %s\n",
        $path,
        $line,
        $severity,
        $code,
        $description
      );
    }

    return implode('', $lines);
  }

  public function renderOkayResult() {
    return "";
  }
}
