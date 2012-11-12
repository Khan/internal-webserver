<?php

/**
 * @group markup
 */
abstract class PhutilSyntaxHighlighterEngine {
  abstract public function setConfig($key, $value);
  abstract public function getHighlightFuture($language, $source);
  abstract public function getLanguageFromFilename($filename);

  final public function highlightSource($language, $source) {
    try {
      return $this->getHighlightFuture($language, $source)->resolve();
    } catch (PhutilSyntaxHighlighterException $ex) {
      return id(new PhutilDefaultSyntaxHighlighter())
        ->getHighlightFuture($source)
        ->resolve();
    }
  }

}
