<?php

final class DifferentialRepositoryField
  extends DifferentialCoreCustomField {

  public function getFieldKey() {
    return 'differential:repository';
  }

  public function getFieldName() {
    return pht('Repository');
  }

  public function getFieldDescription() {
    return pht('Associates a revision with a repository.');
  }

  protected function readValueFromRevision(
    DifferentialRevision $revision) {
    return $revision->getRepositoryPHID();
  }

  protected function writeValueToRevision(
    DifferentialRevision $revision,
    $value) {
    $revision->setRepositoryPHID($value);
  }

  public function readValueFromRequest(AphrontRequest $request) {
    $phids = $request->getArr($this->getFieldKey());
    $first = head($phids);
    $this->setValue(nonempty($first, null));
  }

  public function getRequiredHandlePHIDsForEdit() {
    $phids = array();
    if ($this->getValue()) {
      $phids[] = $this->getValue();
    }
    return $phids;
  }

  public function renderEditControl(array $handles) {
    if ($this->getValue()) {
      $control_value = array_select_keys($handles, array($this->getValue()));
    } else {
      $control_value = array();
    }

    return id(new AphrontFormTokenizerControl())
      ->setName($this->getFieldKey())
      ->setDatasource('/typeahead/common/repositories/')
      ->setValue($control_value)
      ->setError($this->getFieldError())
      ->setLabel($this->getFieldName())
      ->setLimit(1);
  }

  public function getApplicationTransactionRequiredHandlePHIDs(
    PhabricatorApplicationTransaction $xaction) {

    $old = $xaction->getOldValue();
    $new = $xaction->getNewValue();

    $phids = array();
    if ($old) {
      $phids[] = $old;
    }
    if ($new) {
      $phids[] = $new;
    }

    return $phids;
  }

  public function getApplicationTransactionTitle(
    PhabricatorApplicationTransaction $xaction) {
    $author_phid = $xaction->getAuthorPHID();
    $old = $xaction->getOldValue();
    $new = $xaction->getNewValue();

    if ($old && $new) {
      return pht(
        '%s changed the repository for this revision from %s to %s.',
        $xaction->renderHandleLink($author_phid),
        $xaction->renderHandleLink($old),
        $xaction->renderHandleLink($new));
    } else if ($new) {
      return pht(
        '%s set the repository for this revision to %s.',
        $xaction->renderHandleLink($author_phid),
        $xaction->renderHandleLink($new));
    } else {
      return pht(
        '%s removed %s as the repository for this revision.',
        $xaction->renderHandleLink($author_phid),
        $xaction->renderHandleLink($old));
    }
  }

  public function getApplicationTransactionTitleForFeed(
    PhabricatorApplicationTransaction $xaction,
    PhabricatorFeedStory $story) {

    $object_phid = $xaction->getObjectPHID();
    $author_phid = $xaction->getAuthorPHID();
    $old = $xaction->getOldValue();
    $new = $xaction->getNewValue();

    if ($old) {
      return pht(
        '%s updated the repository for %s from %s to %s.',
        $xaction->renderHandleLink($author_phid),
        $xaction->renderHandleLink($object_phid),
        $xaction->renderHandleLink($old),
        $xaction->renderHandleLink($new));
    } else {
      return pht(
        '%s set the repository for %s to %s.',
        $xaction->renderHandleLink($author_phid),
        $xaction->renderHandleLink($object_phid),
        $xaction->renderHandleLink($new));
    }
  }

}
