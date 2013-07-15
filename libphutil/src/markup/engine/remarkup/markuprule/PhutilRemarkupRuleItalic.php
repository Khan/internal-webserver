<?php

/**
 * @group markup
 */
final class PhutilRemarkupRuleItalic
  extends PhutilRemarkupRule {

  public function getPriority() {
    return 1000.0;
  }

  public function apply($text) {
    if ($this->getEngine()->isTextMode()) {
      return $text;
    }

    return $this->replaceHTML(
      '@(?<!:)//(.+?)//@s',
      array($this, 'applyCallback'),
      $text);
  }

  protected function applyCallback($matches) {
    return hsprintf('<em>%s</em>', $matches[1]);
  }

}
