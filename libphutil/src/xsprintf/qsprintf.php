<?php

/**
 * Format an SQL query. This function behaves like sprintf(), except that
 * all the normal conversions (like %s) will be properly escaped, and
 * additional conversions are supported:
 *
 *   %nd, %ns, %nf, %nB
 *     "Nullable" versions of %d, %s, %f and %B. Will produce 'NULL' if the
 *     argument is a strict null.
 *
 *   %=d, %=s, %=f
 *     "Nullable Test" versions of %d, %s and %f. If you pass a value, you
 *     get "= 3"; if you pass null, you get "IS NULL". For instance, this
 *     will work properly if `hatID' is a nullable column and $hat is null.
 *
 *       qsprintf($escaper, 'WHERE hatID %=d', $hat);
 *
 *   %Ld, %Ls, %Lf, %LB
 *     "List" versions of %d, %s, %f and %B. These are appropriate for use in
 *     an "IN" clause. For example:
 *
 *       qsprintf($escaper, 'WHERE hatID IN(%Ld)', $list_of_hats);
 *
 *   %B ("Binary String")
 *     Escapes a string for insertion into a pure binary column, ignoring
 *     tests for characters outside of the basic multilingual plane.
 *
 *   %T ("Table")
 *     Escapes a table name.
 *
 *   %C, %LC
 *     Escapes a column name or a list of column names.
 *
 *   %K ("Comment")
 *     Escapes a comment.
 *
 *   %Q ("Query Fragment")
 *     Injects a raw query fragment. Extremely dangerous! Not escaped!
 *
 *   %~ ("Substring")
 *     Escapes a substring query for a LIKE (or NOT LIKE) clause. For example:
 *
 *       //  Find all rows with $search as a substing of `name`.
 *       qsprintf($escaper, 'WHERE name LIKE %~', $search);
 *
 *     See also %> and %<.
 *
 *   %> ("Prefix")
 *     Escapes a prefix query for a LIKE clause. For example:
 *
 *       //  Find all rows where `name` starts with $prefix.
 *       qsprintf($escaper, 'WHERE name LIKE %>', $prefix);
 *
 *   %< ("Suffix")
 *     Escapes a suffix query for a LIKE clause. For example:
 *
 *       //  Find all rows where `name` ends with $suffix.
 *       qsprintf($escaper, 'WHERE name LIKE %<', $suffix);
 *
 * @group storage
 */
function qsprintf(PhutilQsprintfInterface $escaper, $pattern/* , ... */) {
  $args = func_get_args();
  array_shift($args);
  return xsprintf('xsprintf_query', $escaper, $args);
}

/**
 * @group storage
 */
function vqsprintf(PhutilQsprintfInterface $escaper, $pattern, array $argv) {
  array_unshift($argv, $pattern);
  return xsprintf('xsprintf_query', $escaper, $argv);
}


/**
 * xsprintf() callback for encoding SQL queries. See qsprintf().
 * @group storage
 */
function xsprintf_query($userdata, &$pattern, &$pos, &$value, &$length) {
  $type    = $pattern[$pos];
  $escaper = $userdata;
  $next    = (strlen($pattern) > $pos + 1) ? $pattern[$pos + 1] : null;

  $nullable = false;
  $done     = false;

  $prefix   = '';

  if (!($escaper instanceof PhutilQsprintfInterface)) {
    throw new Exception("Invalid database escaper!");
  }

  switch ($type) {
    case '=': // Nullable test
      switch ($next) {
        case 'd':
        case 'f':
        case 's':
          $pattern = substr_replace($pattern, '', $pos, 1);
          $length  = strlen($pattern);
          $type  = 's';
          if ($value === null) {
            $value = 'IS NULL';
            $done = true;
          } else {
            $prefix = '= ';
            $type = $next;
          }
          break;
        default:
          throw new Exception('Unknown conversion, try %=d, %=s, or %=f.');
      }
      break;

    case 'n': // Nullable...
      switch ($next) {
        case 'd': //  ...integer.
        case 'f': //  ...float.
        case 's': //  ...string.
        case 'B': //  ...binary string.
          $pattern = substr_replace($pattern, '', $pos, 1);
          $length = strlen($pattern);
          $type = $next;
          $nullable = true;
          break;
        default:
          throw new Exception('Unknown conversion, try %nd or %ns.');
      }
      break;

    case 'L': // List of..
      _qsprintf_check_type($value, "L{$next}", $pattern);
      $pattern = substr_replace($pattern, '', $pos, 1);
      $length  = strlen($pattern);
      $type = 's';
      $done = true;

      switch ($next) {
        case 'd': //  ...integers.
          $value = implode(', ', array_map('intval', $value));
          break;
        case 's': // ...strings.
          foreach ($value as $k => $v) {
            $value[$k] = "'".$escaper->escapeUTF8String((string)$v)."'";
          }
          $value = implode(', ', $value);
          break;
        case 'B': // ...binary strings.
          foreach ($value as $k => $v) {
            $value[$k] = "'".$escaper->escapeBinaryString((string)$v)."'";
          }
          $value = implode(', ', $value);
          break;
        case 'C': // ...columns.
          foreach ($value as $k => $v) {
            $value[$k] = $escaper->escapeColumnName($v);
          }
          $value = implode(', ', $value);
          break;
        default:
          throw new Exception("Unknown conversion %L{$next}.");
      }
      break;
  }

  if (!$done) {
    _qsprintf_check_type($value, $type, $pattern);
    switch ($type) {
      case 's': // String
        if ($nullable && $value === null) {
          $value = 'NULL';
        } else {
          $value = "'".$escaper->escapeUTF8String((string)$value)."'";
        }
        $type = 's';
        break;

      case 'B': // Binary String
        if ($nullable && $value === null) {
          $value = 'NULL';
        } else {
          $value = "'".$escaper->escapeBinaryString((string)$value)."'";
        }
        $type = 's';
        break;

      case 'Q': // Query Fragment
        $type = 's';
        break;

      case '~': // Like Substring
      case '>': // Like Prefix
      case '<': // Like Suffix
        $value = $escaper->escapeStringForLikeClause($value);
        switch ($type) {
          case '~': $value = "'%".$value."%'"; break;
          case '>': $value = "'" .$value."%'"; break;
          case '<': $value = "'%".$value. "'"; break;
        }
        $type  = 's';
        break;

      case 'f': // Float
        if ($nullable && $value === null) {
          $value = 'NULL';
        } else {
          $value = (float)$value;
        }
        $type = 's';
        break;

      case 'd': // Integer
        if ($nullable && $value === null) {
          $value = 'NULL';
        } else {
          $value = (int)$value;
        }
        $type = 's';
        break;

      case 'T': // Table
      case 'C': // Column
        $value = $escaper->escapeColumnName($value);
        $type = 's';
        break;

      case 'K': // Komment
        $value = $escaper->escapeMultilineComment($value);
        $type = 's';
        break;

      default:
        throw new Exception("Unknown conversion '%{$type}'.");

    }
  }

  if ($prefix) {
    $value = $prefix.$value;
  }
  $pattern[$pos] = $type;
}


/**
 * @group storage
 */
function _qsprintf_check_type($value, $type, $query) {
  switch ($type) {
    case 'Ld': case 'Ls': case 'LC': case 'LB':
      if (!is_array($value)) {
        throw new AphrontQueryParameterException(
          $query,
          "Expected array argument for %{$type} conversion.");
      }
      if (empty($value)) {
        throw new AphrontQueryParameterException(
          $query,
          "Array for %{$type} conversion is empty.");
      }

      foreach ($value as $scalar) {
        _qsprintf_check_scalar_type($scalar, $type, $query);
      }
      break;
    default:
      _qsprintf_check_scalar_type($value, $type, $query);
      break;
  }
}


/**
 * @group storage
 */
function _qsprintf_check_scalar_type($value, $type, $query) {
  switch ($type) {
    case 'Q': case 'LC': case 'T': case 'C':
      if (!is_string($value)) {
        throw new AphrontQueryParameterException(
          $query,
          "Expected a string for %{$type} conversion.");
      }
      break;

    case 'Ld': case 'd': case 'f':
      if (!is_null($value) && !is_numeric($value)) {
        throw new AphrontQueryParameterException(
          $query,
          "Expected a numeric scalar or null for %{$type} conversion.");
      }
      break;

    case 'Ls': case 's': case 'LB': case 'B':
    case '~': case '>': case '<': case 'K':
      if (!is_null($value) && !is_scalar($value)) {
        throw new AphrontQueryParameterException(
          $query,
          "Expected a scalar or null for %{$type} conversion.");
      }
      break;

    default:
      throw new Exception("Unknown conversion '{$type}'.");
  }
}
