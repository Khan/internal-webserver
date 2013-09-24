<?php

final class PhabricatorStandardCustomFieldSelect
  extends PhabricatorStandardCustomField {

  public function getFieldType() {
    return 'select';
  }

  public function buildFieldIndexes() {
    $indexes = array();

    $value = $this->getFieldValue();
    if (strlen($value)) {
      $indexes[] = $this->newStringIndex($value);
    }

    return $indexes;
  }

  public function readApplicationSearchValueFromRequest(
    PhabricatorApplicationSearchEngine $engine,
    AphrontRequest $request) {
    return $request->getArr($this->getFieldKey());
  }

  public function applyApplicationSearchConstraintToQuery(
    PhabricatorApplicationSearchEngine $engine,
    PhabricatorCursorPagedPolicyAwareQuery $query,
    $value) {
    if ($value) {
      $query->withApplicationSearchContainsConstraint(
        $this->newStringIndex(null),
        $value);
    }
  }

  public function appendToApplicationSearchForm(
    PhabricatorApplicationSearchEngine $engine,
    AphrontFormView $form,
    $value,
    array $handles) {

    if (!is_array($value)) {
      $value = array();
    }
    $value = array_fuse($value);

    $control = id(new AphrontFormCheckboxControl())
      ->setLabel($this->getFieldName());

    foreach ($this->getOptions() as $name => $option) {
      $control->addCheckbox(
        $this->getFieldKey().'[]',
        $name,
        $option,
        isset($value[$name]));
    }

    $form->appendChild($control);
  }

  private function getOptions() {
    return $this->getFieldConfigValue('options', array());
  }

  public function renderEditControl() {
    return id(new AphrontFormSelectControl())
      ->setLabel($this->getFieldName())
      ->setCaption($this->getCaption())
      ->setName($this->getFieldKey())
      ->setValue($this->getFieldValue())
      ->setOptions($this->getOptions());
  }

  public function renderPropertyViewValue() {
    return idx($this->getOptions(), $this->getFieldValue());
  }


  public function getApplicationTransactionTitle(
    PhabricatorApplicationTransaction $xaction) {
    $author_phid = $xaction->getAuthorPHID();
    $old = $xaction->getOldValue();
    $new = $xaction->getNewValue();

    $old = idx($this->getOptions(), $old, $old);
    $new = idx($this->getOptions(), $new, $new);

    if (!$old) {
      return pht(
        '%s set %s to %s.',
        $xaction->renderHandleLink($author_phid),
        $this->getFieldName(),
        $new);
    } else if (!$new) {
      return pht(
        '%s removed %s.',
        $xaction->renderHandleLink($author_phid),
        $this->getFieldName());
    } else {
      return pht(
        '%s changed %s from %s to %s.',
        $xaction->renderHandleLink($author_phid),
        $this->getFieldName(),
        $old,
        $new);
    }
  }

}
