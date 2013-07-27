<?php

/**
 * @group countdown
 */
final class PhabricatorCountdownRemarkupRule
  extends PhabricatorRemarkupRuleObject {

  protected function getObjectNamePrefix() {
    return 'C';
  }

  protected function loadObjects(array $ids) {
    $viewer = $this->getEngine()->getConfig('viewer');
    return id(new PhabricatorCountdownQuery())
      ->setViewer($viewer)
      ->withIDs($ids)
      ->execute();
  }

  protected function renderObjectEmbed($object, $handle, $options) {
    $viewer = $this->getEngine()->getConfig('viewer');

    return id(new PhabricatorCountdownView())
      ->setCountdown($object)
      ->setUser($viewer);
  }

}
