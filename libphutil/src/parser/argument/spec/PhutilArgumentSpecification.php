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

final class PhutilArgumentSpecification {

  private $name;
  private $help;
  private $shortAlias;
  private $paramName;
  private $default;
  private $conflicts  = array();
  private $wildcard;
  private $repeatable;

  /**
   * Convenience constructor for building an argument specification from a
   * dictionary. This just wraps all the setter methods, but allows you to
   * define things a little more compactly. Pass an array of properties:
   *
   *    $spec = PhutilArgumentSpecification::newQuickSpec(
   *      array(
   *        'name'  => 'verbose',
   *        'short' => 'v',
   *      ));
   *
   * Recognized keys and equivalent verbose methods are:
   *
   *    name        setName()
   *    help        setHelp()
   *    short       setShortAlias()
   *    param       setParamName()
   *    default     setDefault()
   *    conflicts   setConflicts()
   *    wildcard    setWildcard()
   *    repeat      setRepeatable()
   *
   * @param dict Dictionary of quick parameter definitions.
   * @return PhutilArgumentSpecification Constructed argument specification.
   */
  public static function newQuickSpec(array $spec) {
    $recognized_keys = array(
      'name',
      'help',
      'short',
      'param',
      'default',
      'conflicts',
      'wildcard',
      'repeat',
    );

    $unrecognized = array_diff_key(
      $spec,
      array_fill_keys($recognized_keys, true));

    foreach ($unrecognized as $key => $ignored) {
      throw new PhutilArgumentSpecificationException(
        "Unrecognized key '{$key}' in argument specification. Recognized keys ".
        "are: ".implode(', ', $recognized_keys).'.');
    }

    $obj = new PhutilArgumentSpecification();

    foreach ($spec as $key => $value) {
      switch ($key) {
        case 'name':
          $obj->setName($value);
          break;
        case 'help':
          $obj->setHelp($value);
          break;
        case 'short':
          $obj->setShortAlias($value);
          break;
        case 'param':
          $obj->setParamName($value);
          break;
        case 'default':
          $obj->setDefault($value);
          break;
        case 'conflicts':
          $obj->setConflicts($value);
          break;
        case 'wildcard':
          $obj->setWildcard($value);
          break;
        case 'repeat':
          $obj->setRepeatable($value);
          break;
      }
    }

    $obj->validate();

    return $obj;
  }

  public static function newSpecsFromList(array $specs) {
    foreach ($specs as $key => $spec) {
      if (is_array($spec)) {
        $specs[$key] = PhutilArgumentSpecification::newQuickSpec(
          $spec);
      }
    }
    return $specs;
  }

  public function setName($name) {
    self::validateName($name);
    $this->name = $name;
    return $this;
  }

  private static function validateName($name) {
    if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $name)) {
      throw new PhutilArgumentSpecificationException(
        "Argument names may only contain a-z, 0-9 and -, and must be ".
        "at least one character long. '{$name}' is invalid.");
    }
  }

  public function getName() {
    return $this->name;
  }

  public function setHelp($help) {
    $this->help = $help;
    return $this;
  }

  public function getHelp() {
    return $this->help;
  }

  public function setShortAlias($short_alias) {
    self::validateShortAlias($short_alias);
    $this->shortAlias = $short_alias;
    return $this;
  }

  private static function validateShortAlias($alias) {
    if (strlen($alias) !== 1) {
      throw new PhutilArgumentSpecificationException(
        "Argument short aliases must be exactly one character long. ".
        "'{$alias}' is invalid.");
    }
    if (!preg_match('/^[a-zA-Z0-9]$/', $alias)) {
      throw new PhutilArgumentSpecificationException(
        "Argument short aliases may only be in a-z, A-Z and 0-9. ".
        "'{$alias}' is invalid.");
    }
  }

  public function getShortAlias() {
    return $this->shortAlias;
  }

  public function setParamName($param_name) {
    $this->paramName = $param_name;
    return $this;
  }

  public function getParamName() {
    return $this->paramName;
  }

  public function setDefault($default) {
    $this->default = $default;
    return $this;
  }

  public function getDefault() {
    if ($this->getParamName() === null) {
      if ($this->getRepeatable()) {
        return 0;
      } else {
        return false;
      }
    } else {
      if ($this->getRepeatable()) {
        return array();
      } else {
        return $this->default;
      }
    }
  }

  public function setConflicts(array $conflicts) {
    $this->conflicts = $conflicts;
    return $this;
  }

  public function getConflicts() {
    return $this->conflicts;
  }

  public function setWildcard($wildcard) {
    $this->wildcard = $wildcard;
    return $this;
  }

  public function getWildcard() {
    return $this->wildcard;
  }

  public function setRepeatable($repeatable) {
    $this->repeatable = $repeatable;
    return $this;
  }

  public function getRepeatable() {
    return $this->repeatable;
  }

  public function validate() {
    if ($this->name === null) {
      throw new PhutilArgumentSpecificationException(
        "Argument specification MUST have a 'name'.");
    }

    if ($this->getWildcard()) {
      if ($this->getParamName()) {
        throw new PhutilArgumentSpecificationException(
          "Wildcard arguments may not specify a parameter.");
      }
      if ($this->getRepeatable()) {
        throw new PhutilArgumentSpecificationException(
          "Wildcard arguments may not be repeatable.");
      }
    }

    if ($this->default !== null) {
      if ($this->getRepeatable()) {
        throw new PhutilArgumentSpecificationException(
          "Repeatable arguments may not have a default (always array() for ".
          "arguments which accept a parameter, or 0 for arguments which do ".
          "not).");
      } else if ($this->getParamName() === null) {
        throw new PhutilArgumentSpecificationException(
          "Flag arguments may not have a default (always false).");
      }
    }
  }

}
