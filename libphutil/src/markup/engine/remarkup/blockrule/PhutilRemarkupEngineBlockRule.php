<?php

/**
 * @group markup
 * @stable
 */
abstract class PhutilRemarkupEngineBlockRule {

  private $engine;
  private $rules = array();

  /**
   * Determine the order in which blocks execute. Blocks with smaller priority
   * numbers execute sooner than blocks with larger priority numbers. The
   * default priority for blocks is `500`.
   *
   * Priorities are used to disambiguate syntax which can match multiple
   * patterns. For example, `  - Lorem ipsum...` may be a code block or a
   * list.
   *
   * @return float  Priority at which this block should execute.
   */
  public function getPriority() {
    return 500.0;
  }

  abstract public function markupText($text);

  /**
   * This will get an array of unparsed lines and return the number of lines
   * from the first array value that it can parse.
   *
   * @param array $lines
   * @param int   $cursor
   *
   * @return int
   */
  abstract public function getMatchingLineCount(array $lines, $cursor);

  protected function didMarkupText() {
    return;
  }

  final public function setEngine(PhutilRemarkupEngine $engine) {
    $this->engine = $engine;
    $this->updateRules();
    return $this;
  }

  final protected function getEngine() {
    return $this->engine;
  }

  public function setMarkupRules(array $rules) {
    assert_instances_of($rules, 'PhutilRemarkupRule');
    $this->rules = $rules;
    $this->updateRules();
    return $this;
  }

  private function updateRules() {
    $engine = $this->getEngine();
    if ($engine) {
      $this->rules = msort($this->rules, 'getPriority');
      foreach ($this->rules as $rule) {
        $rule->setEngine($engine);
      }
    }
    return $this;
  }

  final public function getMarkupRules() {
    return $this->rules;
  }

  final public function postprocess() {
    $this->didMarkupText();
  }

  final protected function applyRules($text) {
    foreach ($this->getMarkupRules() as $rule) {
      $text = $rule->apply($text);
    }
    return $text;
  }

  protected function renderRemarkupTable(array $out_rows) {
    assert_instances_of($out_rows, 'array');

    if ($this->getEngine()->isTextMode()) {
      $lengths = array();
      foreach ($out_rows as $r => $row) {
        foreach ($row['content'] as $c => $cell) {
          $text = $this->getEngine()->restoreText($cell['content']);
          $lengths[$c][$r] = phutil_utf8_strlen($text);
        }
      }
      $max_lengths = array_map('max', $lengths);

      $out = array();
      foreach ($out_rows as $r => $row) {
        $headings = false;
        foreach ($row['content'] as $c => $cell) {
          $length = $max_lengths[$c] - $lengths[$c][$r];
          $out[] = '| '.$cell['content'].str_repeat(' ', $length).' ';
          if ($cell['type'] == 'th') {
            $headings = true;
          }
        }
        $out[] = "|\n";

        if ($headings) {
          foreach ($row['content'] as $c => $cell) {
            $char = ($cell['type'] == 'th' ? '-' : ' ');
            $out[] = '| '.str_repeat($char, $max_lengths[$c]).' ';
          }
          $out[] = "|\n";
        }
      }

      return rtrim(implode('', $out), "\n");
    }

    $out = array();
    $out[] = "\n";
    foreach ($out_rows as $row) {
      $cells = array();
      foreach ($row['content'] as $cell) {
        $cells[] = phutil_tag($cell['type'], array(), $cell['content']);
      }
      $out[] = phutil_tag($row['type'], array(), $cells);
      $out[] = "\n";
    }

    return phutil_tag('table', array('class' => 'remarkup-table'), $out);
  }

}
