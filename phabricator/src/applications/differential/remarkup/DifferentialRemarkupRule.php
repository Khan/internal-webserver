<?php

/**
 * @group differential
 */
final class DifferentialRemarkupRule
  extends PhabricatorRemarkupRuleObject {

  protected function getObjectNamePrefix() {
    return 'D';
  }

  protected function loadObjects(array $ids) {
    $viewer = $this->getEngine()->getConfig('viewer');

    if (!$viewer) {
      return array();
    }

    return id(new DifferentialRevisionQuery())
      ->setViewer($viewer)
      ->withIDs($ids)
      ->execute();
  }

}
