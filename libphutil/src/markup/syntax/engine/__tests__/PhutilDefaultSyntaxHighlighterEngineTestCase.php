<?php

/**
 * Test cases for @{class:PhutilDefaultSyntaxHighlighterEngine}.
 *
 * @group testcase
 */
final class PhutilDefaultSyntaxHighlighterEngineTestCase
  extends PhutilTestCase {

  public function testFilenameGreediness() {
    $names = array(
      'x.php'       => 'php',
      '/x.php'      => 'php',
      'x.y.php'     => 'php',
      '/x.y/z.php'  => 'php',
      '/x.php/'     => null,
    );

    $engine = new PhutilDefaultSyntaxHighlighterEngine();
    foreach ($names as $path => $language) {
      $detect = $engine->getLanguageFromFilename($path);
      $this->assertEqual($language, $detect, 'Language detect for '.$path);
    }
  }

}
