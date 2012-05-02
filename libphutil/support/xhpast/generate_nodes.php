#!/usr/local/bin/php
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

$nodes = <<<EONODES
n_PROGRAM
n_SYMBOL_NAME
n_HALT_COMPILER
n_NAMESPACE
n_STATEMENT
n_EMPTY
n_STATEMENT_LIST
n_OPEN_TAG
n_CLOSE_TAG
n_USE_LIST
n_USE
n_CONSTANT_DECLARATION_LIST
n_CONSTANT_DECLARATION
n_STRING
n_LABEL
n_CONDITION_LIST
n_CONTROL_CONDITION
n_IF
n_ELSEIF
n_ELSE
n_WHILE
n_DO_WHILE
n_FOR
n_FOR_EXPRESSION
n_SWITCH
n_BREAK
n_CONTINUE
n_RETURN
n_GLOBAL_DECLARATION_LIST
n_GLOBAL_DECLARATION
n_STATIC_DECLARATION_LIST
n_STATIC_DECLARATION
n_ECHO_LIST
n_ECHO
n_INLINE_HTML
n_UNSET_LIST
n_UNSET
n_FOREACH
n_FOREACH_EXPRESSION
n_THROW
n_GOTO
n_TRY
n_CATCH_LIST
n_CATCH
n_DECLARE
n_DECLARE_DECLARATION_LIST
n_DECLARE_DECLARATION
n_VARIABLE
n_REFERENCE
n_VARIABLE_REFERENCE
n_FUNCTION_DECLARATION
n_CLASS_DECLARATION
n_CLASS_ATTRIBUTES
n_EXTENDS
n_EXTENDS_LIST
n_IMPLEMENTS_LIST
n_INTERFACE_DECLARATION
n_CASE
n_DEFAULT
n_DECLARATION_PARAMETER_LIST
n_DECLARATION_PARAMETER
n_TYPE_NAME
n_VARIABLE_VARIABLE
n_CLASS_MEMBER_DECLARATION_LIST
n_CLASS_MEMBER_DECLARATION
n_CLASS_CONSTANT_DECLARATION_LIST
n_CLASS_CONSTANT_DECLARATION
n_METHOD_DECLARATION
n_METHOD_MODIFIER_LIST
n_FUNCTION_MODIFIER_LIST
n_CLASS_MEMBER_MODIFIER_LIST
n_EXPRESSION_LIST
n_LIST
n_ASSIGNMENT
n_NEW
n_UNARY_PREFIX_EXPRESSION
n_UNARY_POSTFIX_EXPRESSION
n_BINARY_EXPRESSION
n_TERNARY_EXPRESSION
n_CAST_EXPRESSION
n_CAST
n_OPERATOR
n_ARRAY_LITERAL
n_EXIT_EXPRESSION
n_BACKTICKS_EXPRESSION
n_LEXICAL_VARIABLE_LIST
n_NUMERIC_SCALAR
n_STRING_SCALAR
n_MAGIC_SCALAR
n_CLASS_STATIC_ACCESS
n_CLASS_NAME
n_MAGIC_CLASS_KEYWORD
n_OBJECT_PROPERTY_ACCESS
n_ARRAY_VALUE_LIST
n_ARRAY_VALUE
n_CALL_PARAMETER_LIST
n_VARIABLE_EXPRESSION
n_INCLUDE_FILE
n_HEREDOC
n_FUNCTION_CALL
n_INDEX_ACCESS
n_ASSIGNMENT_LIST
n_METHOD_CALL
n_CONCATENATION_LIST
n_PARENTHETICAL_EXPRESSION
EONODES;

$seq = 9000;
$map = array();
foreach (explode("\n", trim($nodes)) as $node) {
  $map[$node] = $seq++;
}

$hpp = '';
foreach ($map as $node => $value) {
  $hpp .= "#define {$node} {$value}\n";
}
file_put_contents('node_names.hpp', $hpp);
echo "Wrote C++ definition.\n";

$at = '@';
$php =
  "<?php\n\n".
  "/* {$at}generated {$at}undivinable */\n\n".
  "function xhp_parser_node_constants() {\n".
  "  return array(\n";
foreach ($map as $node => $value) {
  $php .= "    {$value} => '{$node}',\n";
}
$php .= "  );\n";
$php .= "}\n";
file_put_contents('parser_nodes.php', $php);
echo "Wrote PHP definition.\n";



