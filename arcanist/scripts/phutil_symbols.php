#!/usr/bin/env php
<?php

// We have to do this first before we load any symbols, because we define the
// builtin symbol list through introspection.
$builtins = phutil_symbols_get_builtins();

require_once dirname(__FILE__).'/__init_script__.php';

$args = new PhutilArgumentParser($argv);
$args->setTagline('identify symbols in a PHP source file');
$args->setSynopsis(<<<EOHELP
    **phutil_symbols.php** [__options__] __path.php__
        Identify the symbols (clases, functions and interfaces) in a PHP
        source file. Symbols are divided into "have" symbols (symbols the file
        declares) and "need" symbols (symbols the file depends on). For example,
        class declarations are "have" symbols, while object instantiations
        with "new X()" are "need" symbols.

        Dependencies on builtins and symbols marked '@phutil-external-symbol'
        in docblocks are omitted without __--all__.

        Symbols are reported in JSON on stdout.

        This script is used internally by libphutil/arcanist to build maps of
        library symbols.

        It would be nice to eventually implement this as a C++ xhpast binary,
        as it's relatively stable and performance is currently awful
        (500ms+ for moderately large files).

EOHELP
);
$args->parseStandardArguments();
$args->parse(
  array(
    array(
      'name'      => 'all',
      'help'      => 'Report all symbols, including builtins and declared '.
                     'externals.',
    ),
    array(
      'name'      => 'ugly',
      'help'      => 'Do not prettify JSON output.',
    ),
    array(
      'name'      => 'path',
      'wildcard'  => true,
      'help'      => 'PHP Source file to analyze.',
    ),
  ));

$paths = $args->getArg('path');
if (count($paths) !== 1) {
  throw new Exception("Specify exactly one path!");
}
$path = Filesystem::resolvePath(head($paths));

$show_all = $args->getArg('all');

$source_code = Filesystem::readFile($path);

try {
  $tree = XHPASTTree::newFromData($source_code);
} catch (XHPASTSyntaxErrorException $ex) {
  $result = array(
    'error' => $ex->getMessage(),
    'line'  => $ex->getErrorLine(),
    'file'  => $path,
  );
  $json = new PhutilJSON();
  echo $json->encodeFormatted($result);
  exit(0);
}

$root = $tree->getRootNode();

$root->buildSelectCache();


// -(  Marked Externals  )------------------------------------------------------


// Identify symbols marked with "@phutil-external-symbol", so we exclude them
// from the dependency list.

$externals = array();
$doc_parser = new PhutilDocblockParser();
foreach ($root->getTokens() as $token) {
  if ($token->getTypeName() == 'T_DOC_COMMENT') {
    list($block, $special) = $doc_parser->parse($token->getValue());

    $ext_list = idx($special, 'phutil-external-symbol');
    $ext_list = explode("\n", $ext_list);
    $ext_list = array_filter($ext_list);

    foreach ($ext_list as $ext_ref) {
      $matches = null;
      if (preg_match('/^\s*(\S+)\s+(\S+)/', $ext_ref, $matches)) {
        $externals[$matches[1]][$matches[2]] = true;
      }
    }
  }
}


// -(  Declarations and Dependencies  )-----------------------------------------


// The first stage of analysis is to find all the symbols we declare in the
// file (like functions and classes) and all the symbols we use in the file
// (like calling functions and invoking classes). Later, we filter this list
// to exclude builtins.


$have = array();  // For symbols we declare.
$need = array();  // For symbols we use.
$xmap = array();  // For extended classes and implemented interfaces.


// -(  Functions  )-------------------------------------------------------------


// Find functions declared in this file.

// This is "function f() { ... }".
$functions = $root->selectDescendantsOfType('n_FUNCTION_DECLARATION');
foreach ($functions as $function) {
  $name = $function->getChildByIndex(2);
  if ($name->getTypeName() == 'n_EMPTY') {
    // This is an anonymous function; don't record it into the symbol
    // index.
    continue;
  }
  $have[] = array(
    'type'    => 'function',
    'symbol'  => $name,
  );
}


// Find functions used by this file. Uses:
//
//   - Explicit Call
//   - String literal passed to call_user_func() or call_user_func_array()
//
// TODO: Possibly support these:
//
//   - String literal in ReflectionFunction().

// This is "f();".
$calls = $root->selectDescendantsOfType('n_FUNCTION_CALL');
foreach ($calls as $call) {
  $name = $call->getChildByIndex(0);
  if ($name->getTypeName() == 'n_VARIABLE' ||
      $name->getTypeName() == 'n_VARIABLE_VARIABLE') {
    // Ignore these, we can't analyze them.
    continue;
  }
  if ($name->getTypeName() == 'n_CLASS_STATIC_ACCESS') {
    // These are "C::f()", we'll pick this up later on.
    continue;
  }
  $call_name = $name->getConcreteString();
  if ($call_name == 'call_user_func' ||
      $call_name == 'call_user_func_array') {
    $params = $call->getChildByIndex(1)->getChildren();
    if (!count($params)) {
      // This is a bare call_user_func() with no arguments; just ignore it.
      continue;
    }
    $symbol = array_shift($params);
    $symbol_value = $symbol->getStringLiteralValue();
    if ($symbol_value) {
      $need[] = array(
        'type'    => 'function',
        'name'    => $symbol_value,
        'symbol'  => $symbol,
      );
    }
  } else {
    $need[] = array(
      'type'    => 'function',
      'symbol'  => $name,
    );
  }
}


// -(  Classes  )---------------------------------------------------------------


// Find classes declared by this file.


// This is "class X ... { ... }".
$classes = $root->selectDescendantsOfType('n_CLASS_DECLARATION');
foreach ($classes as $class) {
  $class_name = $class->getChildByIndex(1);
  $have[] = array(
    'type'    => 'class',
    'symbol'  => $class_name,
  );
}


// Find classes used by this file. We identify these:
//
//  - class ... extends X
//  - new X
//  - Static method call
//  - Static property access
//  - Use of class constant
//
// TODO: Possibly support these:
//
//  - typehints
//  - instanceof
//  - catch
//  - String literal in ReflectionClass().
//  - String literal in array literal in call_user_func()/call_user_func_array()


// This is "class X ... { ... }".
$classes = $root->selectDescendantsOfType('n_CLASS_DECLARATION');
foreach ($classes as $class) {
  $class_name = $class->getChildByIndex(1)->getConcreteString();
  $extends = $class->getChildByIndex(2);
  foreach ($extends->selectDescendantsOfType('n_CLASS_NAME') as $parent) {
    $need[] = array(
      'type'    => 'class',
      'symbol'  => $parent,
    );

    // Track all 'extends' in the extension map.
    $xmap[$class_name][] = $parent->getConcreteString();
  }
}

$magic_names = array(
  'static' => true,
  'parent' => true,
  'self'   => true,
);

// This is "new X()".
$uses_of_new = $root->selectDescendantsOfType('n_NEW');
foreach ($uses_of_new as $new_operator) {
  $name = $new_operator->getChildByIndex(0);
  if ($name->getTypeName() == 'n_VARIABLE' ||
      $name->getTypeName() == 'n_VARIABLE_VARIABLE') {
    continue;
  }
  $name_concrete = strtolower($name->getConcreteString());
  if (isset($magic_names[$name_concrete])) {
    continue;
  }
  $need[] = array(
    'type'    => 'class',
    'symbol'  => $name,
  );
}

// This covers all of "X::$y", "X::y()" and "X::CONST".
$static_uses = $root->selectDescendantsOfType('n_CLASS_STATIC_ACCESS');
foreach ($static_uses as $static_use) {
  $name = $static_use->getChildByIndex(0);
  if ($name->getTypeName() != 'n_CLASS_NAME') {
    continue;
  }
  $name_concrete = strtolower($name->getConcreteString());
  if (isset($magic_names[$name_concrete])) {
    continue;
  }
  $need[] = array(
    'type'    => 'class',
    'symbol'  => $name,
  );
}


// -(  Interfaces  )------------------------------------------------------------


// Find interfaces declared in ths file.


// This is "interface X .. { ... }".
$interfaces = $root->selectDescendantsOfType('n_INTERFACE_DECLARATION');
foreach ($interfaces as $interface) {
  $interface_name = $interface->getChildByIndex(1);
  $have[] = array(
    'type'      => 'interface',
    'symbol'    => $interface_name,
  );
}


// Find interfaces used by this file. We identify these:
//
//  - class ... implements X
//  - interface ... extends X


// This is "class X ... { ... }".
$classes = $root->selectDescendantsOfType('n_CLASS_DECLARATION');
foreach ($classes as $class) {
  $class_name = $class->getChildByIndex(1)->getConcreteString();
  $implements = $class->getChildByIndex(3);
  $interfaces = $implements->selectDescendantsOfType('n_CLASS_NAME');
  foreach ($interfaces as $interface) {
    $need[] = array(
      'type'    => 'interface',
      'symbol'  => $interface,
    );

    // Track 'class ... implements' in the extension map.
    $xmap[$class_name][] = $interface->getConcreteString();
  }
}


// This is "interface X ... { ... }".
$interfaces = $root->selectDescendantsOfType('n_INTERFACE_DECLARATION');
foreach ($interfaces as $interface) {
  $interface_name = $interface->getChildByIndex(1)->getConcreteString();

  $extends = $interface->getChildByIndex(2);
  foreach ($extends->selectDescendantsOfType('n_CLASS_NAME') as $parent) {
    $need[] = array(
      'type'    => 'interface',
      'symbol'  => $parent,
    );

    // Track 'interface ... extends' in the extension map.
    $xmap[$interface_name][] = $parent->getConcreteString();
  }
}


// -(  Analysis  )--------------------------------------------------------------


$declared_symbols = array();
foreach ($have as $key => $spec) {
  $name = $spec['symbol']->getConcreteString();
  $declared_symbols[$spec['type']][$name] = $spec['symbol']->getOffset();
}

$required_symbols = array();
foreach ($need as $key => $spec) {
  $name = idx($spec, 'name');
  if (!$name) {
    $name = $spec['symbol']->getConcreteString();
  }

  $type = $spec['type'];
  if (!$show_all) {
    if (!empty($externals[$type][$name])) {
      // Ignore symbols declared as externals.
      continue;
    }
    if (!empty($builtins[$type][$name])) {
      // Ignore symbols declared as builtins.
      continue;
    }
  }
  if (!empty($required_symbols[$type][$name])) {
    // Report only the first use of a symbol, since reporting all of them
    // isn't terribly informative.
    continue;
  }
  if (!empty($declared_symbols[$type][$name])) {
    // We declare this symbol, so don't treat it as a requirement.
    continue;
  }
  $required_symbols[$type][$name] = $spec['symbol']->getOffset();
}

$result = array(
  'have'  => $declared_symbols,
  'need'  => $required_symbols,
  'xmap'  => $xmap,
);


// -(  Output  )----------------------------------------------------------------


if ($args->getArg('ugly')) {
  echo json_encode($result);
} else {
  $json = new PhutilJSON();
  echo $json->encodeFormatted($result);
}


// -(  Library  )---------------------------------------------------------------


function phutil_symbols_get_builtins() {
  $builtin = array();
  $builtin['classes']    = get_declared_classes();
  $builtin['interfaces'] = get_declared_interfaces();

  $funcs  = get_defined_functions();
  $builtin['functions']  = $funcs['internal'];

  foreach (array('functions', 'classes') as $type) {
    // Developers may not have every extension that a library potentially uses
    // installed. We supplement the list of declared functions and classses with
    // a list of known extension functions to avoid raising false positives just
    // because you don't have pcntl, etc.
    $list = dirname(__FILE__)."/php_extension_{$type}.txt";
    $extensions = file_get_contents($list);
    $extensions = explode("\n", trim($extensions));
    $builtin[$type] = array_merge($builtin[$type], $extensions);
  }

  return array(
    'class'     => array_fill_keys($builtin['classes'], true) + array(
      'PhutilBootloader' => true,
    ),
    'function'  => array_filter(
      array(
        'empty' => true,
        'isset' => true,
        'die'   => true,

        // These are provided by libphutil but not visible in the map.

        'phutil_is_windows'   => true,
        'phutil_load_library' => true,
        'phutil_is_hiphop_runtime' => true,

        // HPHP/i defines these functions as 'internal', but they are NOT
        // builtins and do not exist in vanilla PHP. Make sure we don't mark
        // them as builtin since we need to add dependencies for them.
        'idx'   => false,
        'id'    => false,
      ) + array_fill_keys($builtin['functions'], true)),
    'interface' => array_fill_keys($builtin['interfaces'], true),
  );
}
