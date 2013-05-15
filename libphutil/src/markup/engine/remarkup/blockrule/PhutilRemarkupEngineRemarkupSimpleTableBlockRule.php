<?php

/**
 * @group markup
 */
final class PhutilRemarkupEngineRemarkupSimpleTableBlockRule
  extends PhutilRemarkupEngineBlockRule {

  public function getBlockPattern() {
    return '/^(\|.*+\n?)+$/';
  }

  public function shouldMergeBlocks() {
    return false;
  }

  public function markupText($text) {
    $matches = array();

    $rows = array();
    foreach (explode("\n", $text) as $line) {
      // Ignore ending delimiters.
      $line = rtrim($line, '|');

      // NOTE: The complexity in this regular expression allows us to match
      // a table like "| a | [[ href | b ]] | c |".

      preg_match_all(
        '/\|'.
        '('.
          '(?:'.
            '(?:\\[\\[.*?\\]\\])'. // [[ ... | ... ]], a link
            '|'.
              '(?:[^|[]+)'.          // Anything but "|" or "[".
            '|'.
              '(?:\\[[^\\|[])'.      // "[" followed by anything but "[" or "|"
          ')*'.
        ')/', $line, $matches);

      $headings = true;
      $cells = array();
      foreach ($matches[1] as $cell) {
        $cell = trim($cell);

        // Cell isn't empty and doesn't look like heading.
        if (!preg_match('/^(|--+)$/', $cell)) {
          $headings = false;
        }
        $cells[] = array('type' => 'td', 'content' => $this->applyRules($cell));
      }

      if (!$headings) {
        $rows[] = array('type' => 'tr', 'content' => $cells);
      } else if ($rows) {
        // Mark previous row with headings.
        foreach ($cells as $i => $cell) {
          if ($cell['content']) {
            $rows[last_key($rows)]['content'][$i]['type'] = 'th';
          }
        }
      }
    }

    if (!$rows) {
      return $this->applyRules($text);
    }

    return $this->renderRemarkupTable($rows);
  }

}
