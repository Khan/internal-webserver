<?php

final class DifferentialDependsOnField
  extends DifferentialCustomField {

  public function getFieldKey() {
    return 'differential:depends-on';
  }

  public function getFieldKeyForConduit() {
    return 'phabricator:depends-on';
  }

  public function getFieldName() {
    return pht('Depends On');
  }

  public function canDisableField() {
    return false;
  }

  public function getFieldDescription() {
    return pht('Lists revisions this one depends on.');
  }

  public function shouldAppearInPropertyView() {
    return true;
  }

  public function renderPropertyViewLabel() {
    return $this->getFieldName();
  }

  public function getRequiredHandlePHIDsForPropertyView() {
    return PhabricatorEdgeQuery::loadDestinationPHIDs(
      $this->getObject()->getPHID(),
      PhabricatorEdgeConfig::TYPE_DREV_DEPENDS_ON_DREV);
  }

  public function renderPropertyViewValue(array $handles) {
    return $this->renderHandleList($handles);
  }

  public function getProTips() {
    return array(
      pht(
        'Create a dependendency between revisions by writing '.
        '"Depends on D123" in your summary.'),
    );
  }

  public function shouldAppearInConduitDictionary() {
    return true;
  }

  public function getConduitDictionaryValue() {
    return PhabricatorEdgeQuery::loadDestinationPHIDs(
      $this->getObject()->getPHID(),
      PhabricatorEdgeConfig::TYPE_DREV_DEPENDS_ON_DREV);
  }

}
