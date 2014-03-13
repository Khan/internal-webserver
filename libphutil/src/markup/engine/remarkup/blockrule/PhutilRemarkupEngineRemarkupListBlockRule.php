<?php

/**
 * @group markup
 */
final class PhutilRemarkupEngineRemarkupListBlockRule
  extends PhutilRemarkupEngineBlockRule {

  /**
   * This rule must apply before the Code block rule because it needs to
   * win blocks which begin `  - Lorem ipsum`.
   */
  public function getPriority() {
    return 400;
  }

  public function getMatchingLineCount(array $lines, $cursor) {
    $num_lines = 0;

    while (isset($lines[$cursor])) {
      if (!$num_lines) {
        if (preg_match(self::START_BLOCK_PATTERN, $lines[$cursor])) {
          $num_lines++;
          $cursor++;
          continue;
        }
      } else {
        if (preg_match(self::CONT_BLOCK_PATTERN, $lines[$cursor])) {
          $num_lines++;
          $cursor++;
          continue;
        }

        if (strlen(trim($lines[$cursor]))
          && strlen($lines[$cursor]) !== strlen(ltrim($lines[$cursor]))) {
          $num_lines++;
          $cursor++;
          continue;
        }

        if (!strlen(trim($lines[$cursor]))) {
          $num_lines++;
        }
      }

      break;
    }

    return $num_lines;
  }

  /**
   * The maximum sub-list depth you can nest to. Avoids silliness and blowing
   * the stack.
   */
  const MAXIMUM_LIST_NESTING_DEPTH = 12;
  const START_BLOCK_PATTERN = '@^\s*(?:[-*#]+|1[.)])\s+@';
  const CONT_BLOCK_PATTERN = '@^\s*(?:[-*#]+|[0-9]+[.)])\s+@';

  public function markupText($text) {

    $items = array();
    $lines = explode("\n", $text);

    // We allow users to delimit lists using either differing indentation
    // levels:
    //
    //   - a
    //     - b
    //
    // ...or differing numbers of item-delimiter characters:
    //
    //   - a
    //   -- b
    //
    // If they use the second style but block-indent the whole list, we'll
    // get the depth counts wrong for the first item. To prevent this,
    // un-indent every item by the minimum indentation level for the whole
    // block before we begin parsing.

    $regex = self::START_BLOCK_PATTERN;
    $min_space = PHP_INT_MAX;
    foreach ($lines as $ii => $line) {
      $matches = null;
      if (preg_match($regex, $line)) {
        $regex = self::CONT_BLOCK_PATTERN;
        if (preg_match('/^(\s+)/', $line, $matches)) {
          $space = strlen($matches[1]);
        } else {
          $space = 0;
        }
        $min_space = min($min_space, $space);
      }
    }

    $regex = self::START_BLOCK_PATTERN;
    if ($min_space) {
      foreach ($lines as $key => $line) {
        if (preg_match($regex, $line)) {
          $regex = self::CONT_BLOCK_PATTERN;
          $lines[$key] = substr($line, $min_space);
        }
      }
    }


    // The input text may have linewraps in it, like this:
    //
    //   - derp derp derp derp
    //     derp derp derp derp
    //   - blarp blarp blarp blarp
    //
    // Group text lines together into list items, stored in $items. So the
    // result in the above case will be:
    //
    //   array(
    //     array(
    //       "- derp derp derp derp",
    //       "  derp derp derp derp",
    //     ),
    //     array(
    //       "- blarp blarp blarp blarp",
    //     ),
    //   );

    $item = array();
    $regex = self::START_BLOCK_PATTERN;
    foreach ($lines as $line) {
      if (preg_match($regex, $line)) {
        $regex = self::CONT_BLOCK_PATTERN;
        if ($item) {
          $items[] = $item;
          $item = array();
        }
      }
      $item[] = $line;
    }
    if ($item) {
      $items[] = $item;
    }


    // Process each item to normalize the text, remove line wrapping, and
    // determine its depth (indentation level) and style (ordered vs unordered).
    //
    // Given the above example, the processed array will look like:
    //
    //   array(
    //     array(
    //       'text'  => 'derp derp derp derp derp derp derp derp',
    //       'depth' => 0,
    //       'style' => '-',
    //     ),
    //     array(
    //       'text'  => 'blarp blarp blarp blarp',
    //       'depth' => 0,
    //       'style' => '-',
    //     ),
    //   );

    foreach ($items as $key => $item) {
      $item = preg_replace('/\s*\n\s*/', ' ', implode("\n", $item));
      $item = rtrim($item);

      if (!strlen($item)) {
        unset($items[$key]);
        continue;
      }

      $matches = null;
      if (preg_match('/^\s*([-*#]{2,})/', $item, $matches)) {
        // Alternate-style indents; use number of list item symbols.
        $depth = strlen($matches[1]) - 1;
      } else if (preg_match('/^(\s+)/', $item, $matches)) {
        // Markdown-style indents; use indent depth.
        $depth = strlen($matches[1]);
      } else {
        $depth = 0;
      }

      if (preg_match('/^\s*(?:#|[0-9])/', $item)) {
        $style = '#';
      } else {
        $style = '-';
      }

      // If we don't match the block pattern (for example, because the user
      // has typed only " " or " -"), treat the line as containing no text.
      // This prevents newly added items from rendering with a bullet and
      // the text "-", e.g.
      $text = preg_replace(self::CONT_BLOCK_PATTERN, '', $item);
      if ($text == $item) {
        $text = '';
      }

      $items[$key] = array(
        'text'  => $text,
        'depth' => $depth,
        'style' => $style,
      );
    }
    $items = array_values($items);


    // Users can create a sub-list by indenting any deeper amount than the
    // previous list, so these are both valid:
    //
    //   - a
    //     - b
    //
    //   - a
    //       - b
    //
    // In the former case, we'll have depths (0, 2). In the latter case, depths
    // (0, 4). We don't actually care about how many spaces there are, only
    // how many list indentation levels (that is, we want to map both of
    // those cases to (0, 1), indicating "outermost list" and "first sublist").
    //
    // This is made more complicated because lists at two different indentation
    // levels might be at the same list level:
    //
    //   - a
    //     - b
    //   - c
    //       - d
    //
    // Here, 'b' and 'd' are at the same list level (2) but different indent
    // levels (2, 4).
    //
    // Users can also create "staircases" like this:
    //
    //       - a
    //     - b
    //   # c
    //
    // While this is silly, we'd like to render it as faithfully as possible.
    //
    // In order to do this, we convert the list of nodes into a tree,
    // normalizing indentation levels and inserting dummy nodes as necessary to
    // make the tree well-formed. See additional notes at buildTree().
    //
    // In the case above, the result is a tree like this:
    //
    //   - <null>
    //     - <null>
    //       - a
    //     - b
    //   # c

    $l = 0;
    $r = count($items);
    $tree = $this->buildTree($items, $l, $r, $cur_level = 0);


    // We may need to open a list on a <null> node, but they do not have
    // list style information yet. We need to propagate list style inforamtion
    // backward through the tree. In the above example, the tree now looks
    // like this:
    //
    //   - <null (style=#)>
    //     - <null (style=-)>
    //       - a
    //     - b
    //   # c

    $this->adjustTreeStyleInformation($tree);

    // Finally, we have enough information to render the tree.

    $out = $this->renderTree($tree);

    if ($this->getEngine()->isTextMode()) {
      $out = implode('', $out);
      $out = rtrim($out, "\n");
      $out = preg_replace('/ +$/m', '', $out);
      return $out;
    }

    return phutil_implode_html('', $out);
  }

  /**
   * See additional notes in markupText().
   */
  private function buildTree(array $items, $l, $r, $cur_level) {
    if ($l == $r) {
      return array();
    }

    if ($cur_level > self::MAXIMUM_LIST_NESTING_DEPTH) {
      // This algorithm is recursive and we don't need you blowing the stack
      // with your oh-so-clever 50,000-item-deep list. Cap indentation levels
      // at a reasonable number and just shove everything deeper up to this
      // level.
      $nodes = array();
      for ($ii = $l; $ii < $r; $ii++) {
        $nodes[] = array(
          'level' => $cur_level,
          'items' => array(),
        ) + $items[$ii];
      }
      return $nodes;
    }

    $min = $l;
    for ($ii = $r - 1; $ii >= $l; $ii--) {
      if ($items[$ii]['depth'] < $items[$min]['depth']) {
        $min = $ii;
      }
    }

    $min_depth = $items[$min]['depth'];

    $nodes = array();
    if ($min != $l) {
      $nodes[] = array(
        'text'    => null,
        'level'   => $cur_level,
        'style'   => null,
        'items'   => $this->buildTree($items, $l, $min, $cur_level + 1),
      );
    }

    $last = $min;
    for ($ii = $last + 1; $ii < $r; $ii++) {
      if ($items[$ii]['depth'] == $min_depth) {
        $nodes[] = array(
          'level' => $cur_level,
          'items' => $this->buildTree($items, $last + 1, $ii, $cur_level + 1),
        ) + $items[$last];
        $last = $ii;
      }
    }
    $nodes[] = array(
      'level' => $cur_level,
      'items' => $this->buildTree($items, $last + 1, $r, $cur_level + 1),
    ) + $items[$last];

    return $nodes;
  }


  /**
   * See additional notes in markupText().
   */
  private function adjustTreeStyleInformation(array &$tree) {

    // The effect here is just to walk backward through the nodes at this level
    // and apply the first style in the list to any empty nodes we inserted
    // before it. As we go, also recurse down the tree.

    $style = '-';
    for ($ii = count($tree) - 1; $ii >= 0; $ii--) {
      if ($tree[$ii]['style'] !== null) {
        // This is the earliest node we've seen with style, so set the
        // style to its style.
        $style = $tree[$ii]['style'];
      } else {
        // This node has no style, so apply the current style.
        $tree[$ii]['style'] = $style;
      }
      if ($tree[$ii]['items']) {
        $this->adjustTreeStyleInformation($tree[$ii]['items']);
      }
    }
  }


  /**
   * See additional notes in markupText().
   */
  private function renderTree(array $tree, $level = 0) {
    $style = idx(head($tree), 'style');

    $out = array();

    if (!$this->getEngine()->isTextMode()) {
      switch ($style) {
        case '#':
          $out[] = hsprintf("<ol>\n");
          break;
        case '-':
          $out[] = hsprintf("<ul>\n");
          break;
      }
    }

    $number = 1;
    foreach ($tree as $item) {
      if ($this->getEngine()->isTextMode()) {
        $out[] = str_repeat(' ', 2 * $level);
        switch ($style) {
          case '#':
            $out[] = $number.'. ';
            $number++;
            break;
          case '-':
            $out[] = '- ';
            break;
        }
        $out[] = $this->applyRules($item['text'])."\n";
      } else if ($item['text'] === null) {
        $out[] = hsprintf('<li class="phantom-item">');
      } else {
        $out[] = hsprintf('<li>');
        $out[] = $this->applyRules($item['text']);
      }
      if ($item['items']) {
        foreach ($this->renderTree($item['items'], $level + 1) as $i) {
          $out[] = $i;
        }
      }
      if (!$this->getEngine()->isTextMode()) {
        $out[] = hsprintf("</li>\n");
      }
    }

    if (!$this->getEngine()->isTextMode()) {
      switch ($style) {
        case '#':
          $out[] = hsprintf('</ol>');
          break;
        case '-':
          $out[] = hsprintf('</ul>');
          break;
      }
    }

    return $out;
  }


}
