<?php

final class PhabricatorTokenGivenFeedStory
  extends PhabricatorFeedStory {

  public function getPrimaryObjectPHID() {
    return $this->getValue('objectPHID');
  }

  public function getRequiredHandlePHIDs() {
    $phids = array();
    $phids[] = $this->getValue('objectPHID');
    $phids[] = $this->getValue('authorPHID');
    return $phids;
  }

  public function getRequiredObjectPHIDs() {
    $phids = array();
    $phids[] = $this->getValue('tokenPHID');
    return $phids;
  }

  public function renderView() {
    $view = $this->newStoryView();
    $view->setAppIcon('token-dark');
    $author_phid = $this->getValue('authorPHID');

    $href = $this->getHandle($this->getPrimaryObjectPHID())->getURI();
    $view->setHref($href);

    $view->setTitle($this->renderTitle());
    $view->setImage($this->getHandle($author_phid)->getImageURI());

    return $view;
  }

  private function renderTitle() {
    $token = $this->getObject($this->getValue('tokenPHID'));
    $title = pht(
      '%s awarded %s a %s token.',
      $this->linkTo($this->getValue('authorPHID')),
      $this->linkTo($this->getValue('objectPHID')),
      $token->getName());

    return $title;
  }

  public function renderText() {
    $old_target = $this->getRenderingTarget();
    $this->setRenderingTarget(PhabricatorApplicationTransaction::TARGET_TEXT);
    $title = $this->renderTitle();
    $this->setRenderingTarget($old_target);
    return $title;
  }

}
