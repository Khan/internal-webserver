<?php

final class ArcanistCommentRemover {

  /**
   * Remove comment lines from a commit message. Strips trailing lines only,
   * and requires "#" to appear at the beginning of a line for it to be
   * considered a comment.
   */
  public static function removeComments($body) {
    $lines = explode("\n", $body);
    $lines = array_reverse($lines);
    foreach ($lines as $key => $line) {
      if (!strlen($line)) {
        unset($lines[$key]);
        continue;
      }
      if ($line[0] == '#') {
        unset($lines[$key]);
        continue;
      }
      break;
    }
    $lines = array_reverse($lines);
    return implode("\n", $lines)."\n";
  }

}
