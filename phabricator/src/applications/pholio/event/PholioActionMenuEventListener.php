<?php

final class PholioActionMenuEventListener
  extends PhabricatorEventListener {

  public function register() {
    $this->listen(PhabricatorEventType::TYPE_UI_DIDRENDERACTIONS);
  }

  public function handleEvent(PhutilEvent $event) {
    switch ($event->getType()) {
      case PhabricatorEventType::TYPE_UI_DIDRENDERACTIONS:
        $this->handleActionsEvent($event);
      break;
    }
  }

  private function handleActionsEvent(PhutilEvent $event) {
    $object = $event->getValue('object');

    $actions = null;
    if ($object instanceof ManiphestTask) {
      $actions = $this->renderTaskItems($event);
    }

    $this->addActionMenuItems($event, $actions);
  }

  private function renderTaskItems(PhutilEvent $event) {
    if (!$this->canUseApplication($event->getUser())) {
      return;
    }

    $task = $event->getValue('object');
    $phid = $task->getPHID();

    $can_edit = PhabricatorPolicyFilter::hasCapability(
      $event->getUser(),
      $task,
      PhabricatorPolicyCapability::CAN_EDIT);

    return  id(new PhabricatorActionView())
      ->setName(pht('Edit Pholio Mocks'))
      ->setHref("/search/attach/{$phid}/MOCK/edge/")
      ->setWorkflow(true)
      ->setIcon('attach')
      ->setDisabled(!$can_edit)
      ->setWorkflow(true);
  }

}
