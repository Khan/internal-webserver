<?php

/*
 * Copyright 2012 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * Test cases for @{class:PhutilRemarkupEngine}.
 *
 * @group testcase
 */
final class PhutilRemarkupEngineTestCase extends PhutilTestCase {

  public function testEngine() {
    $root = dirname(__FILE__).'/remarkup/';
    foreach (Filesystem::listDirectory($root, $hidden = false) as $file) {
      $this->markupText($root.$file);
    }
  }

  private function markupText($markup_file) {
    $contents = Filesystem::readFile($markup_file);
    $file = basename($markup_file);

    $parts = explode("\n~~~~~~~~~~\n", $contents);
    $this->assertEqual(2, count($parts));

    list($input_remarkup, $expected_output) = $parts;

    switch ($file) {
      case 'raw-escape.txt':

        // NOTE: Here, we want to test PhutilRemarkupRuleEscapeRemarkup and
        // PhutilRemarkupBlockStorage, which are triggered by "\1". In the
        // test, "~" is used as a placeholder for "\1" since it's hard to type
        // "\1".

        $input_remarkup = str_replace("~", "\1", $input_remarkup);
        $expected_output = str_replace("~", "\1", $expected_output);
        break;
    }

    $engine = $this->buildNewTestEngine();

    $actual_output = $engine->markupText($input_remarkup);

    $this->assertEqual(
      $expected_output,
      $actual_output,
      "Failed to markup file '{$file}'.");
  }

  private function buildNewTestEngine() {
    $engine = new PhutilRemarkupEngine();

    $engine->setConfig(
      'uri.allowed-protocols',
      array(
        'http' => true,
      ));

    $rules = array();
    $rules[] = new PhutilRemarkupRuleEscapeRemarkup();
    $rules[] = new PhutilRemarkupRuleMonospace();
    $rules[] = new PhutilRemarkupRuleDocumentLink();
    $rules[] = new PhutilRemarkupRuleHyperlink();
    $rules[] = new PhutilRemarkupRuleEscapeHTML();
    $rules[] = new PhutilRemarkupRuleBold();
    $rules[] = new PhutilRemarkupRuleItalic();
    $rules[] = new PhutilRemarkupRuleDel();

    $blocks = array();
    $blocks[] = new PhutilRemarkupEngineRemarkupHeaderBlockRule();
    $blocks[] = new PhutilRemarkupEngineRemarkupListBlockRule();
    $blocks[] = new PhutilRemarkupEngineRemarkupCodeBlockRule();
    $blocks[] = new PhutilRemarkupEngineRemarkupNoteBlockRule();
    $blocks[] = new PhutilRemarkupEngineRemarkupSimpleTableBlockRule();
    $blocks[] = new PhutilRemarkupEngineRemarkupDefaultBlockRule();

    foreach ($blocks as $block) {
      if (!($block instanceof PhutilRemarkupEngineRemarkupCodeBlockRule)) {
        $block->setMarkupRules($rules);
      }
    }

    $engine->setBlockRules($blocks);

    return $engine;
  }

}
