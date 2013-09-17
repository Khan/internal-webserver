<?php

/**
 * @group markup
 */
final class PhutilRemarkupEngineRemarkupInterpreterRule
  extends PhutilRemarkupEngineBlockRule {

  const START_BLOCK_PATTERN = '/^([\w]+)\s(?:\(([^)]+)\)\s)?{{{/';
  const END_BLOCK_PATTERN = '/}}}\s*$/';

  public function getMatchingLineCount(array $lines, $cursor) {
    $num_lines = 0;

    if (preg_match(self::START_BLOCK_PATTERN, $lines[$cursor])) {
      $num_lines++;

      while (isset($lines[$cursor])) {
        if (preg_match(self::END_BLOCK_PATTERN, $lines[$cursor])) {
          break;
        }
        $num_lines++;
        $cursor++;
      }
    }

    return $num_lines;
  }

  public function markupText($text) {

    $lines = explode("\n", $text);
    $first_key = head_key($lines);
    $last_key = last_key($lines);
    while (trim($lines[$last_key]) === '') {
      unset($lines[$last_key]);
      $last_key = last_key($lines);
    }
    $matches = null;

    preg_match(self::START_BLOCK_PATTERN, head($lines), $matches);

    $argv = array();
    if (isset($matches[2])) {
      $argv = id(new PhutilSimpleOptions())->parse($matches[2]);
    }

    $interpreters = id(new PhutilSymbolLoader())
      ->setAncestorClass('PhutilRemarkupBlockInterpreter')
      ->loadObjects();

    $lines[$first_key] = preg_replace(
      self::START_BLOCK_PATTERN,
      "",
      $lines[$first_key]);
    $lines[$last_key] = preg_replace(
      self::END_BLOCK_PATTERN,
      "",
      $lines[$last_key]);

    if (trim($lines[$first_key]) === '') {
      unset($lines[$first_key]);
    }
    if (trim($lines[$last_key]) === '') {
      unset($lines[$last_key]);
    }

    $content = implode("\n", $lines);

    $interpreters = mpull($interpreters, null, 'getInterpreterName');

    if (isset($interpreters[$matches[1]])) {
      return $interpreters[$matches[1]]->markupContent($content, $argv);
    }

    $message = sprintf('No interpreter found: %s', $matches[1]);

    if ($this->getEngine()->isTextMode()) {
      return '('.$message.')';
    }

    return hsprintf('<div style="color: red;">%s</div>', $message);
  }

}
