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
 * @group markup
 */
final class PhutilRemarkupEngine extends PhutilMarkupEngine {

  private $blockRules = array();
  private $config = array();
  private $metadata = array();

  public function setConfig($key, $value) {
    $this->config[$key] = $value;
    return $this;
  }

  public function getConfig($key, $default = null) {
    return idx($this->config, $key, $default);
  }

  public function setBlockRules(array $rules) {
    assert_instances_of($rules, 'PhutilRemarkupEngineBlockRule');
    $this->blockRules = $rules;
    return $this;
  }

  public function getTextMetadata($key, $default = null) {
    return idx($this->metadata, $key, $default);
  }

  public function setTextMetadata($key, $value) {
    $this->metadata[$key] = $value;
    return $this;
  }

  public function storeText($text) {
    return $this->storage->store($text);
  }

  public function overwriteStoredText($token, $new_text) {
    $this->storage->overwrite($token, $new_text);
    return $this;
  }

  public function markupText($text) {
    return $this->postprocessText($this->preprocessText($text));
  }

  private function setupProcessing() {
    $this->metadata = array();
    $this->storage = new PhutilRemarkupBlockStorage();

    $block_rules = $this->blockRules;
    if (empty($block_rules)) {
      throw new Exception("Remarkup engine not configured with block rules.");
    }
    foreach ($block_rules as $rule) {
      $rule->setEngine($this);
    }
  }

  public function preprocessText($text) {
    $this->setupProcessing();

    // Apply basic block and paragraph normalization to the text. NOTE: We don't
    // strip trailing whitespace because it is semantic in some contexts,
    // notably inlined diffs that the author intends to show as a code block.
    $text = preg_replace("/\r\n?/", "\n", $text);
    $text = preg_split("/\n\n/", $text);

    $block_rules = $this->blockRules;

    $blocks = array();
    $last = null;
    $last_block = null;
    foreach ($text as $block) {

      $action = null;
      if ($last !== null) {
        if ($block_rules[$last]->shouldContinueWithBlock($block, $last_block)) {
          $action = 'merge';
        }
      }

      if (!$action) {
        foreach ($block_rules as $key => $block_rule) {
          if (!$block_rule->shouldMatchBlock(trim($block, "\n"))) {
            continue;
          }
          if (($last !== null) &&
              ($key == $last) &&
              $block_rule->shouldMergeBlocks()) {
            $action = 'merge';
          } else {
            $action = 'append';
          }
          $last = $key;
          break;
        }
      }

      $last_block = $block;

      switch ($action) {
        case 'merge':
          end($blocks);
          $last_block_key = key($blocks);
          $blocks[$last_block_key]['block'] .= "\n\n".$block;
          $last_block = $blocks[$last_block_key]['block'];
          break;
        case 'append':
          $blocks[] = array(
            'rule'  => $block_rules[$last],
            'block' => $block,
          );
          break;
        default:
          throw new Exception("Block in text did not match any block rule.");
      }
    }

    $output = array();
    foreach ($blocks as $block) {
      $output[] = $block['rule']->markupText($block['block']);
    }

    $map = $this->storage->getMap();
    unset($this->storage);
    $metadata = $this->metadata;

    return array(
      'output'    => implode("\n\n", $output),
      'storage'   => $map,
      'metadata'  => $metadata,
    );
  }

  public function postprocessText(array $dict) {
    $this->setupProcessing();

    $this->metadata = idx($dict, 'metadata', array());
    $this->storage->setMap(idx($dict, 'storage', array()));

    foreach ($this->blockRules as $block_rule) {
      $block_rule->postprocess();
    }

    return $this->storage->restore(idx($dict, 'output'));
  }
}
