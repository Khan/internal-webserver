<?php

final class PhabricatorTokenUIEventListener
  extends PhutilEventListener {

  public function register() {
    $this->listen(PhabricatorEventType::TYPE_UI_DIDRENDERACTIONS);
    $this->listen(PhabricatorEventType::TYPE_UI_WILLRENDERPROPERTIES);
  }

  public function handleEvent(PhutilEvent $event) {
    switch ($event->getType()) {
      case PhabricatorEventType::TYPE_UI_DIDRENDERACTIONS:
        $this->handleActionEvent($event);
        break;
      case PhabricatorEventType::TYPE_UI_WILLRENDERPROPERTIES:
        $this->handlePropertyEvent($event);
        break;
    }
  }

  private function handleActionEvent($event) {
    $user = $event->getUser();
    $object = $event->getValue('object');

    if (!$object || !$object->getPHID()) {
      // No object, or the object has no PHID yet..
      return;
    }

    if (!($object instanceof PhabricatorTokenReceiverInterface)) {
      // This object isn't a token receiver.
      return;
    }

    $current = id(new PhabricatorTokenGivenQuery())
      ->setViewer($user)
      ->withAuthorPHIDs(array($user->getPHID()))
      ->withObjectPHIDs(array($object->getPHID()))
      ->execute();

    if (!$current) {
      $token_action = id(new PhabricatorActionView())
        ->setWorkflow(true)
        ->setHref('/token/give/'.$object->getPHID().'/')
        ->setName(pht('Award Token'))
        ->setIcon('like');
    } else {
      $token_action = id(new PhabricatorActionView())
        ->setWorkflow(true)
        ->setHref('/token/give/'.$object->getPHID().'/')
        ->setName(pht('Rescind Token'))
        ->setIcon('dislike');
    }
    if (!$user->isLoggedIn()) {
      $token_action->setDisabled(true);
    }

    $actions = $event->getValue('actions');
    $actions[] = $token_action;
    $event->setValue('actions', $actions);
  }

  private function handlePropertyEvent($event) {
    $user = $event->getUser();
    $object = $event->getValue('object');

    if (!$object || !$object->getPHID()) {
      // No object, or the object has no PHID yet..
      return;
    }

    if (!($object instanceof PhabricatorTokenReceiverInterface)) {
      // This object isn't a token receiver.
      return;
    }

    $limit = 1;

    $tokens_given = id(new PhabricatorTokenGivenQuery())
      ->setViewer($user)
      ->withObjectPHIDs(array($object->getPHID()))
      ->execute();

    if (!$tokens_given) {
      return;
    }

    $tokens = id(new PhabricatorTokenQuery())
      ->setViewer($user)
      ->withPHIDs(mpull($tokens_given, 'getTokenPHID'))
      ->execute();
    $tokens = mpull($tokens, null, 'getPHID');

    $author_phids = mpull($tokens_given, 'getAuthorPHID');
    $handles = id(new PhabricatorHandleQuery())
      ->setViewer($user)
      ->withPHIDs($author_phids)
      ->execute();

    Javelin::initBehavior('phabricator-tooltips');

    $list = array();
    foreach ($tokens_given as $token_given) {
      if (!idx($tokens, $token_given->getTokenPHID())) {
        continue;
      }

      $token = $tokens[$token_given->getTokenPHID()];

      $list[] = javelin_tag(
        'span',
        array(
          'sigil' => 'has-tooltip',
          'meta' => array(
            'tip' => $handles[$token_given->getAuthorPHID()]->getName(),
          ),
        ),
        $token->renderIcon());
    }

    $view = $event->getValue('view');
    $view->addProperty(pht('Tokens'), $list);
  }

}
