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
final class PhutilXHPASTSyntaxHighlighter {

  private $config = array();

  public function setConfig($key, $value) {
    $this->config[$key] = $value;
    return $this;
  }

  public function getHighlightFuture($source) {
    try {
      $result = $this->applyXHPHighlight($source);
      return new ImmediateFuture($result);
    } catch (Exception $ex) {
      if (!empty($this->config['pygments.enabled'])) {
        // Fall back to Pygments if we failed to highlight using XHP. The XHP
        // highlighter currently uses a parser, not just a lexer, so it fails on
        // snippets which aren't valid syntactically.
        return id(new PhutilPygmentsSyntaxHighlighter())
          ->setConfig('language', 'php')
          ->getHighlightFuture($source);
      } else {
        return id(new PhutilDefaultSyntaxHighlighter())
          ->getHighlightFuture($source);
      }
    }
  }

  private function applyXHPHighlight($source) {

    // We perform two passes here: one using the AST to find symbols we care
    // about -- particularly, class names and function names. These are used
    // in the crossreference stuff to link into Diffusion. After we've done our
    // AST pass, we do a followup pass on the token stream to catch all the
    // simple stuff like strings and comments.

    $scrub = false;
    if (strpos($source, '<?') === false) {
      $source = "<?php\n".$source."\n";
      $scrub = true;
    }

    $tree = XHPASTTree::newFromData($source);
    $root = $tree->getRootNode();

    $tokens = $root->getTokens();
    $interesting_symbols = $this->findInterestingSymbols($root);

    $out = array();
    foreach ($tokens as $key => $token) {
      $value = phutil_escape_html($token->getValue());
      $class = null;
      $multi = false;
      $attrs = '';
      if (isset($interesting_symbols[$key])) {
        $sym = $interesting_symbols[$key];
        $class = $sym[0];
        if (isset($sym['context'])) {
          $attrs = $attrs.' data-symbol-context="'.$sym['context'].'"';
        }
        if (isset($sym['symbol'])) {
          $attrs = $attrs.' data-symbol-name="'.$sym['symbol'].'"';
        }
      } else {
        switch ($token->getTypeName()) {
          case 'T_WHITESPACE':
            break;
          case 'T_DOC_COMMENT':
            $class = 'dc';
            $multi = true;
            break;
          case 'T_COMMENT':
            $class = 'c';
            $multi = true;
            break;
          case 'T_CONSTANT_ENCAPSED_STRING':
          case 'T_ENCAPSED_AND_WHITESPACE':
          case 'T_INLINE_HTML':
            $class = 's';
            $multi = true;
            break;
          case 'T_VARIABLE':
            $class = 'nv';
            break;
          case 'T_OPEN_TAG':
          case 'T_OPEN_TAG_WITH_ECHO':
          case 'T_CLOSE_TAG':
            $class = 'o';
            break;
          case 'T_LNUMBER':
          case 'T_DNUMBER':
            $class = 'm';
            break;
          case 'T_STRING':
            static $magic = array(
              'true' => true,
              'false' => true,
              'null' => true,
            );
            if (isset($magic[$value])) {
              $class = 'k';
              break;
            }
            $class = 'nx';
            break;
          default:
            $class = 'k';
            break;
        }
      }
      if ($class) {
        $l = '<span class="'.$class.'"'.$attrs.'>';
        $r = '</span>';

        $value = $l.$value.$r;
        if ($multi) {
          // If the token may have multiple lines in it, make sure each
          // <span> crosses no more than one line so the lines can be put
          // in a table, etc., later.
          $value = str_replace(
            "\n",
            $r."\n".$l,
            $value);
        }
        $out[] = $value;
      } else {
        $out[] = $value;
      }
    }

    if ($scrub) {
      array_shift($out);
    }

    return rtrim(implode('', $out));
  }

  private function findInterestingSymbols(XHPASTNode $root) {
    // Class name symbols appear in:
    //    class X extends X implements X, X { ... }
    //    new X();
    //    $x instanceof X
    //    catch (X $x)
    //    function f(X $x)
    //    X::f();
    //    X::$m;
    //    X::CONST;

    // These are PHP builtin tokens which can appear in a classname context.
    // Don't link them since they don't go anywhere useful.
    static $builtin_class_tokens = array(
      'self'    => true,
      'parent'  => true,
      'static'  => true,
    );

    // Fortunately XHPAST puts all of these in a special node type so it's
    // easy to find them.
    $result_map = array();
    $class_names = $root->selectDescendantsOfType('n_CLASS_NAME');
    foreach ($class_names as $class_name) {
      foreach ($class_name->getTokens() as $key => $token) {
        if (isset($builtin_class_tokens[$token->getValue()])) {
          // This is something like "self::method()".
          continue;
        }
        $result_map[$key] = array(
          'nc', // "Name, Class"
          'symbol' => $class_name->getConcreteString(),
        );
      }
    }

    // Function name symbols appear in:
    //    f()

    $function_calls = $root->selectDescendantsOfType('n_FUNCTION_CALL');
    foreach ($function_calls as $call) {
      $call = $call->getChildByIndex(0);
      if ($call->getTypeName() == 'n_SYMBOL_NAME') {
        // This is a normal function call, not some $f() shenanigans.
        foreach ($call->getTokens() as $key => $token) {
          $result_map[$key] = array(
            'nf', // "Name, Function"
            'symbol' => $call->getConcreteString(),
          );
        }
      }
    }

    // Upon encountering $x->y, link y without context, since $x is unknown.

    $prop_access = $root->selectDescendantsOfType('n_OBJECT_PROPERTY_ACCESS');
    foreach ($prop_access as $access) {
      $right = $access->getChildByIndex(1);
      if ($right->getTypeName() == 'n_INDEX_ACCESS') {
        // otherwise $x->y[0] doesn't get highlighted
        $right = $right->getChildByIndex(0);
      }
      if ($right->getTypeName() == 'n_STRING') {
        foreach ($right->getTokens() as $key => $token) {
          $result_map[$key] = array(
            'na', // "Name, Attribute"
            'symbol' => $right->getConcreteString(),
          );
        }
      }
    }

    // Upon encountering x::y, try to link y with context x.

    $static_access = $root->selectDescendantsOfType('n_CLASS_STATIC_ACCESS');
    foreach ($static_access as $access) {
      $class = $access->getChildByIndex(0);
      $right = $access->getChildByIndex(1);
      if ($class->getTypeName() == 'n_CLASS_NAME' &&
          ($right->getTypeName() == 'n_STRING' ||
           $right->getTypeName() == 'n_VARIABLE')) {
        $classname = head($class->getTokens())->getValue();
        $result = array(
          'na',
          'symbol' => ltrim($right->getConcreteString(), '$'),
        );
        if (!isset($builtin_class_tokens[$classname])) {
          $result['context'] = $classname;
        }
        foreach ($right->getTokens() as $key => $token) {
          $result_map[$key] = $result;
        }
      }
    }

    return $result_map;
  }

}
