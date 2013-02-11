<?php

final class DifferentialDependsOnFieldSpecification
  extends DifferentialFieldSpecification {

  public function shouldAppearOnRevisionView() {
    return true;
  }

  public function getRequiredHandlePHIDsForRevisionView() {
    return $this->getDependentRevisionPHIDs();
  }

  public function renderLabelForRevisionView() {
    return 'Depends On:';
  }

  public function renderValueForRevisionView() {
    $revision_phids = $this->getDependentRevisionPHIDs();
    if (!$revision_phids) {
      return null;
    }

    $links = array();
    foreach ($revision_phids as $revision_phids) {
      $links[] = $this->getHandle($revision_phids)->renderLink();
    }

    return array_interleave(phutil_tag('br'), $links);
  }

  private function getDependentRevisionPHIDs() {
    return PhabricatorEdgeQuery::loadDestinationPHIDs(
      $this->getRevision()->getPHID(),
      PhabricatorEdgeConfig::TYPE_DREV_DEPENDS_ON_DREV);
  }

}
