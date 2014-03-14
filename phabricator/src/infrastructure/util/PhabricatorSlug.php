<?php

final class PhabricatorSlug {

  public static function normalize($slug) {
    $slug = preg_replace('@/+@', '/', $slug);
    $slug = trim($slug, '/');
    $slug = phutil_utf8_strtolower($slug);
    $slug = preg_replace("@[\\x00-\\x19#%&+=\\\\?<> ]+@", '_', $slug);
    $slug = preg_replace('@_+@', '_', $slug);
    $slug = trim($slug, '_');

    // Specifically rewrite these slugs. It's OK to have a slug like "a..b",
    // but not a slug which is only "..".

    // NOTE: These are explicitly not pht()'d, because they should be stable
    // across languages.

    $replace = array(
      '.'   => 'dot',
      '..'  => 'dotdot',
    );

    foreach ($replace as $pattern => $replacement) {
      $pattern = preg_quote($pattern, '@');
      $slug = preg_replace(
        '@(^|/)'.$pattern.'(\z|/)@',
        '\1'.$replacement.'\2', $slug);
    }

    return $slug.'/';
  }

  public static function getDefaultTitle($slug) {
    $parts = explode('/', trim($slug, '/'));
    $default_title = end($parts);
    $default_title = str_replace('_', ' ', $default_title);
    $default_title = phutil_utf8_ucwords($default_title);
    $default_title = nonempty($default_title, pht('Untitled Document'));
    return $default_title;
  }

  public static function getAncestry($slug) {
    $slug = self::normalize($slug);

    if ($slug == '/') {
      return array();
    }

    $ancestors = array(
      '/',
    );

    $slug = explode('/', $slug);
    array_pop($slug);
    array_pop($slug);

    $accumulate = '';
    foreach ($slug as $part) {
      $accumulate .= $part.'/';
      $ancestors[] = $accumulate;
    }

    return $ancestors;
  }

  public static function getDepth($slug) {
    $slug = self::normalize($slug);
    if ($slug == '/') {
      return 0;
    } else {
      return substr_count($slug, '/');
    }
  }

}
