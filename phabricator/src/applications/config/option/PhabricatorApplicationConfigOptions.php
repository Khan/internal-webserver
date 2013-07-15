<?php

abstract class PhabricatorApplicationConfigOptions extends Phobject {

  abstract public function getName();
  abstract public function getDescription();
  abstract public function getOptions();

  public function validateOption(PhabricatorConfigOption $option, $value) {
    if ($value === $option->getDefault()) {
      return;
    }

    if ($value === null) {
      return;
    }

    if ($option->isCustomType()) {
      return $option->getCustomObject()->validateOption($option, $value);
    }

    switch ($option->getType()) {
      case 'bool':
        if ($value !== true &&
            $value !== false) {
          throw new PhabricatorConfigValidationException(
            pht(
              "Option '%s' is of type bool, but value is not true or false.",
              $option->getKey()));
        }
        break;
      case 'int':
        if (!is_int($value)) {
          throw new PhabricatorConfigValidationException(
            pht(
              "Option '%s' is of type int, but value is not an integer.",
              $option->getKey()));
        }
        break;
      case 'string':
        if (!is_string($value)) {
          throw new PhabricatorConfigValidationException(
            pht(
              "Option '%s' is of type string, but value is not a string.",
              $option->getKey()));
        }
        break;
      case 'class':
        $symbols = id(new PhutilSymbolLoader())
          ->setType('class')
          ->setAncestorClass($option->getBaseClass())
          ->setConcreteOnly(true)
          ->selectSymbolsWithoutLoading();
        $names = ipull($symbols, 'name', 'name');
        if (empty($names[$value])) {
          throw new PhabricatorConfigValidationException(
            pht(
              "Option '%s' value must name a class extending '%s'.",
              $option->getKey(),
              $option->getBaseClass()));
        }
        break;
      case 'set':
        $valid = true;
        if (!is_array($value)) {
          throw new PhabricatorConfigValidationException(
            pht(
              "Option '%s' must be a set, but value is not an array.",
              $option->getKey()));
        }
        foreach ($value as $v) {
          if ($v !== true) {
            throw new PhabricatorConfigValidationException(
              pht(
                "Option '%s' must be a set, but array contains values other ".
                "than 'true'.",
                $option->getKey()));
          }
        }
        break;
      case 'list<string>':
        $valid = true;
        if (!is_array($value)) {
          throw new PhabricatorConfigValidationException(
            pht(
              "Option '%s' must be a list of strings, but value is not ".
              "an array.",
              $option->getKey()));
        }
        if ($value && array_keys($value) != range(0, count($value) - 1)) {
          throw new PhabricatorConfigValidationException(
            pht(
              "Option '%s' must be a list of strings, but the value is a ".
              "map with unnatural keys.",
              $option->getKey()));
        }
        foreach ($value as $v) {
          if (!is_string($v)) {
            throw new PhabricatorConfigValidationException(
              pht(
                "Option '%s' must be a list of strings, but it contains one ".
                "or more non-strings.",
                $option->getKey()));
          }
        }
        break;
      case 'wild':
      default:
        break;
    }

    $this->didValidateOption($option, $value);
  }

  protected function didValidateOption(
    PhabricatorConfigOption $option,
    $value) {
    // Hook for subclasses to do complex validation.
    return;
  }

  /**
   * Hook to render additional hints based on, e.g., the viewing user, request,
   * or other context. For example, this is used to show workspace IDs when
   * configuring `asana.workspace-id`.
   *
   * @param   PhabricatorConfigOption   Option being rendered.
   * @param   AphrontRequest            Active request.
   * @return  wild                      Additional contextual description
   *                                    information.
   */
  public function renderContextualDescription(
    PhabricatorConfigOption $option,
    AphrontRequest $request) {
    return null;
  }

  public function getKey() {
    $class = get_class($this);
    $matches = null;
    if (preg_match('/^Phabricator(.*)ConfigOptions$/', $class, $matches)) {
      return strtolower($matches[1]);
    }
    return strtolower(get_class($this));
  }

  final protected function newOption($key, $type, $default) {
    return id(new PhabricatorConfigOption())
      ->setKey($key)
      ->setType($type)
      ->setDefault($default)
      ->setGroup($this);
  }

  final public static function loadAll($external_only = false) {
    $symbols = id(new PhutilSymbolLoader())
      ->setAncestorClass('PhabricatorApplicationConfigOptions')
      ->setConcreteOnly(true)
      ->selectAndLoadSymbols();

    $groups = array();
    foreach ($symbols as $symbol) {
      if ($external_only && $symbol['library'] == 'phabricator') {
        continue;
      }

      $obj = newv($symbol['name'], array());
      $key = $obj->getKey();
      if (isset($groups[$key])) {
        $pclass = get_class($groups[$key]);
        $nclass = $symbol['name'];

        throw new Exception(
          "Multiple PhabricatorApplicationConfigOptions subclasses have the ".
          "same key ('{$key}'): {$pclass}, {$nclass}.");
      }
      $groups[$key] = $obj;
    }

    return $groups;
  }

  final public static function loadAllOptions($external_only = false) {
    $groups = self::loadAll($external_only);

    $options = array();
    foreach ($groups as $group) {
      foreach ($group->getOptions() as $option) {
        $key = $option->getKey();
        if (isset($options[$key])) {
          throw new Exception(
            "Mulitple PhabricatorApplicationConfigOptions subclasses contain ".
            "an option named '{$key}'!");
        }
        $options[$key] = $option;
      }
    }

    return $options;
  }

  /**
   * Deformat a HEREDOC for use in remarkup by converting line breaks to
   * spaces.
   */
  final protected function deformat($string) {
    return preg_replace('/(?<=\S)\n(?=\S)/', ' ', $string);
  }

}
