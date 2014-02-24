<?php

/**
 * Provides a convenient field for storing a set of levels that you can use to
 * filter requests on.
 *
 * Levels are rendered with names and descriptions in the edit UI, and are
 * automatically documented via the "arc request" interface.
 *
 * See ReleephSeverityFieldSpecification for an example.
 */
abstract class ReleephLevelFieldSpecification
  extends ReleephFieldSpecification {

  private $error;

  abstract public function getLevels();
  abstract public function getDefaultLevel();
  abstract public function getNameForLevel($level);
  abstract public function getDescriptionForLevel($level);

  public function getStorageKey() {
    throw new PhabricatorCustomFieldImplementationIncompleteException($this);
  }

  public function renderValueForHeaderView() {
    return $this->getNameForLevel($this->getValue());
  }

  public function renderEditControl(array $handles) {
    $control_name = $this->getRequiredStorageKey();
    $all_levels = $this->getLevels();

    $level = $this->getValue();
    if (!$level) {
      $level = $this->getDefaultLevel();
    }

    $control = id(new AphrontFormRadioButtonControl())
      ->setLabel('Level')
      ->setName($control_name)
      ->setValue($level);

    if ($this->error) {
      $control->setError($this->error);
    } elseif ($this->getDefaultLevel()) {
      $control->setError(true);
    }

    foreach ($all_levels as $level) {
      $name = $this->getNameForLevel($level);
      $description = $this->getDescriptionForLevel($level);
      $control->addButton($level, $name, $description);
    }

    return $control;
  }

  public function renderHelpForArcanist() {
    $text = '';
    $levels = $this->getLevels();
    $default = $this->getDefaultLevel();
    foreach ($levels as $level) {
      $name = $this->getNameForLevel($level);
      $description = $this->getDescriptionForLevel($level);
      $default_marker = ' ';
      if ($level === $default) {
        $default_marker = '*';
      }
      $text .= "    {$default_marker} **{$name}**\n";
      $text .= phutil_console_wrap($description."\n", 8);
    }
    return $text;
  }

  public function validate($value) {
    if ($value === null) {
      $this->error = 'Required';
      $label = $this->getName();
      throw new ReleephFieldParseException(
        $this,
        "You must provide a {$label} level");
    }

    $levels = $this->getLevels();
    if (!in_array($value, $levels)) {
      $label = $this->getName();
      throw new ReleephFieldParseException(
        $this,
        "Level '{$value}' is not a valid {$label} level in this project.");
    }
  }

  public function setValueFromConduitAPIRequest(ConduitAPIRequest $request) {
    $key = $this->getRequiredStorageKey();
    $label = $this->getName();
    $name = idx($request->getValue('fields', array()), $key);

    if (!$name) {
      $level = $this->getDefaultLevel();
      if (!$level) {
        throw new ReleephFieldParseException(
          $this,
          "No value given for {$label}, ".
          "and no default is given for this level!");
      }
    } else {
      $level = $this->getLevelByName($name);
    }

    if (!$level) {
      throw new ReleephFieldParseException(
        $this,
        "Unknown {$label} level name '{$name}'");
    }
    $this->setValue($level);
  }

  private $nameMap = array();

  public function getLevelByName($name) {
    // Build this once
    if (!$this->nameMap) {
      foreach ($this->getLevels() as $level) {
        $level_name = $this->getNameForLevel($level);
        $this->nameMap[$level_name] = $level;
      }
    }
    return idx($this->nameMap, $name);
  }

}
