<?php

/**
 * Dumping ground for diff- and diff-algorithm-related miscellany.
 *
 * @group diff
 */
final class ArcanistDiffUtils {

  /**
   * Make a best-effort attempt to determine if a file is definitely binary.
   *
   * @return bool If true, the file is almost certainly binary. If false, the
   *              file might still be binary but is subtle about it.
   */
  public static function isHeuristicBinaryFile($data) {
    // Detect if a file is binary according to the Git heuristic, which is the
    // presence of NULL ("\0") bytes. Git only examines the first "few" bytes of
    // each file (8KB or so) as an optimization, but we don't have a reasonable
    // equivalent in PHP, so just look at all of it.
    return (strpos($data, "\0") !== false);
  }

  public static function renderDifferences(
    $old,
    $new,
    $context_lines = 3,
    $diff_options  = "-L 'Old Value' -L 'New Value'") {

    if ((string)$old === (string)$new) {
      $new .= "\n(Old and new values are identical.)";
    }

    $file_old = new TempFile();
    $file_new = new TempFile();

    Filesystem::writeFile($file_old, (string)$old."\n");
    Filesystem::writeFile($file_new, (string)$new."\n");

    list($err, $stdout) = exec_manual(
      'diff %C -U %s %s %s',
      $diff_options,
      $context_lines,
      $file_old,
      $file_new);

    return $stdout;
  }

  public static function generateIntralineDiff($o, $n) {
    $ol = strlen($o);
    $nl = strlen($n);

    if (($o === $n) || !$ol || !$nl) {
      return array(
        array(array(0, $ol)),
        array(array(0, $nl))
      );
    }

    return self::computeIntralineEdits($o, $n);
  }

  public static function applyIntralineDiff($str, $intra_stack) {
    $buf = '';
    $p = $s = $e = 0; // position, start, end
    $highlight = $tag = $ent = false;
    $highlight_o = '<span class="bright">';
    $highlight_c = '</span>';

    $is_html = false;
    if ($str instanceof PhutilSafeHTML) {
      $is_html = true;
      $str = $str->getHTMLContent();
    }

    $n = strlen($str);
    for ($i = 0; $i < $n; $i++) {

      if ($p == $e) {
        do {
          if (empty($intra_stack)) {
            $buf .= substr($str, $i);
            break 2;
          }
          $stack = array_shift($intra_stack);
          $s = $e;
          $e += $stack[1];
        } while ($stack[0] == 0);
      }

      if (!$highlight && !$tag && !$ent && $p == $s) {
        $buf .= $highlight_o;
        $highlight = true;
      }

      if ($str[$i] == '<') {
        $tag = true;
        if ($highlight) {
          $buf .= $highlight_c;
        }
      }

      if (!$tag) {
        if ($str[$i] == '&') {
          $ent = true;
        }
        if ($ent && $str[$i] == ';') {
          $ent = false;
        }
        if (!$ent) {
          $p++;
        }
      }

      $buf .= $str[$i];

      if ($tag && $str[$i] == '>') {
        $tag = false;
        if ($highlight) {
          $buf .= $highlight_o;
        }
      }

      if ($highlight && ($p == $e || $i == $n - 1)) {
        $buf .= $highlight_c;
        $highlight = false;
      }
    }

    if ($is_html) {
      return phutil_safe_html($buf);
    }

    return $buf;
  }

  private static function collapseIntralineRuns($runs) {
    $count = count($runs);
    for ($ii = 0; $ii < $count - 1; $ii++) {
      if ($runs[$ii][0] == $runs[$ii + 1][0]) {
        $runs[$ii + 1][1] += $runs[$ii][1];
        unset($runs[$ii]);
      }
    }
    return array_values($runs);
  }

  public static function generateEditString(array $ov, array $nv, $max = 80) {
    return id(new PhutilEditDistanceMatrix())
      ->setComputeString(true)
      ->setAlterCost(1 / ($max * 2))
      ->setReplaceCost(2)
      ->setMaximumLength($max)
      ->setSequences($ov, $nv)
      ->getEditString();
  }

  public static function computeIntralineEdits($o, $n) {
    if (preg_match('/[\x80-\xFF]/', $o.$n)) {
      $ov = phutil_utf8v_combined($o);
      $nv = phutil_utf8v_combined($n);
      $multibyte = true;
    } else {
      $ov = str_split($o);
      $nv = str_split($n);
      $multibyte = false;
    }

    $result = self::generateEditString($ov, $nv);

    // Smooth the string out, by replacing short runs of similar characters
    // with 'x' operations. This makes the result more readable to humans, since
    // there are fewer choppy runs of short added and removed substrings.
    do {
      $original = $result;
      $result = preg_replace(
        '/([xdi])(s{3})([xdi])/',
        '$1xxx$3',
        $result);
      $result = preg_replace(
        '/([xdi])(s{2})([xdi])/',
        '$1xx$3',
        $result);
      $result = preg_replace(
        '/([xdi])(s{1})([xdi])/',
        '$1x$3',
        $result);
    } while ($result != $original);

    // Now we have a character-based description of the edit. We need to
    // convert into a byte-based description. Walk through the edit string and
    // adjust each operation to reflect the number of bytes in the underlying
    // character.

    $o_pos = 0;
    $n_pos = 0;
    $result_len = strlen($result);
    $o_run = array();
    $n_run = array();

    $old_char_len = 1;
    $new_char_len = 1;

    for ($ii = 0; $ii < $result_len; $ii++) {
      $c = $result[$ii];

      if ($multibyte) {
        $old_char_len = strlen($ov[$o_pos]);
        $new_char_len = strlen($nv[$n_pos]);
      }

      switch ($c) {
        case 's':
        case 'x':
          $byte_o = $old_char_len;
          $byte_n = $new_char_len;
          $o_pos++;
          $n_pos++;
          break;
        case 'i':
          $byte_o = 0;
          $byte_n = $new_char_len;
          $n_pos++;
          break;
        case 'd':
          $byte_o = $old_char_len;
          $byte_n = 0;
          $o_pos++;
          break;
      }

      if ($byte_o) {
        if ($c == 's') {
          $o_run[] = array(0, $byte_o);
        } else {
          $o_run[] = array(1, $byte_o);
        }
      }

      if ($byte_n) {
        if ($c == 's') {
          $n_run[] = array(0, $byte_n);
        } else {
          $n_run[] = array(1, $byte_n);
        }
      }
    }

    $o_run = self::collapseIntralineRuns($o_run);
    $n_run = self::collapseIntralineRuns($n_run);

    return array($o_run, $n_run);
  }

}
