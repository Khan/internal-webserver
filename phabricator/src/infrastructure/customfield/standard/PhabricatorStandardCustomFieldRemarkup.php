<?php

final class PhabricatorStandardCustomFieldRemarkup
  extends PhabricatorStandardCustomField {

  public function getFieldType() {
    return 'remarkup';
  }

  public function renderEditControl(array $handles) {
    return id(new PhabricatorRemarkupControl())
      ->setLabel($this->getFieldName())
      ->setName($this->getFieldKey())
      ->setCaption($this->getCaption())
      ->setValue($this->getFieldValue());
  }

  public function getStyleForPropertyView() {
    return 'block';
  }

  public function renderPropertyViewValue(array $handles) {
    $value = $this->getFieldValue();

    if (!strlen($value)) {
      return null;
    }

    // TODO: Once this stabilizes, it would be nice to let fields batch this.
    // For now, an extra query here and there on object detail pages isn't the
    // end of the world.

    $viewer = $this->getViewer();
    return PhabricatorMarkupEngine::renderOneObject(
      id(new PhabricatorMarkupOneOff())->setContent($value),
      'default',
      $viewer);
  }

  public function getApplicationTransactionTitle(
    PhabricatorApplicationTransaction $xaction) {
    $author_phid = $xaction->getAuthorPHID();

    // TODO: Expose fancy transactions.

    return pht(
      '%s edited %s.',
      $xaction->renderHandleLink($author_phid),
      $this->getFieldName());
  }

  public function shouldAppearInHerald() {
    return true;
  }

  public function getHeraldFieldConditions() {
    return array(
      HeraldAdapter::CONDITION_CONTAINS,
      HeraldAdapter::CONDITION_NOT_CONTAINS,
      HeraldAdapter::CONDITION_IS,
      HeraldAdapter::CONDITION_IS_NOT,
      HeraldAdapter::CONDITION_REGEXP,
    );
  }


}
