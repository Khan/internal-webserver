<?php

/**
 * @group markup
 */
final class PhutilRemarkupRuleItalic
  extends PhutilRemarkupRule {

  public function apply($text) {
    return $this->replaceHTML(
      '@(?<!:)//(.+?)//@s',
      array($this, 'applyCallback'),
      $text);
  }

  protected function applyCallback($matches) {
    return hsprintf('<em>%s</em>', $matches[1]);
  }

}
