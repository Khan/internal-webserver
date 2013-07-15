<?php

/**
 * @group markup
 */
final class PhutilRemarkupEngineRemarkupDefaultBlockRule
  extends PhutilRemarkupEngineBlockRule {

  public function getMatchingLineCount(array $lines, $cursor) {
    return 1;
  }

  public function markupText($text) {
    $text = trim($text);
    $text = $this->applyRules($text);

    if ($this->getEngine()->isTextMode()) {
      if (!$this->getEngine()->getConfig('preserve-linebreaks')) {
        $text = preg_replace('/ *\n */', ' ', $text);
      }
      return $text;
    }

    if ($this->getEngine()->getConfig('preserve-linebreaks')) {
      $text = phutil_escape_html_newlines($text);
    }

    return phutil_tag('p', array(), $text);
  }

}
