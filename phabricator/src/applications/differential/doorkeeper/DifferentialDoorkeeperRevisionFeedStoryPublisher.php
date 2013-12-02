<?php

final class DifferentialDoorkeeperRevisionFeedStoryPublisher
  extends DoorkeeperFeedStoryPublisher {

  public function canPublishStory(PhabricatorFeedStory $story, $object) {
    return ($object instanceof DifferentialRevision);
  }

  public function isStoryAboutObjectCreation($object) {
    $story = $this->getFeedStory();
    $action = $story->getStoryData()->getValue('action');

    return ($action == DifferentialAction::ACTION_CREATE);
  }

  public function isStoryAboutObjectClosure($object) {
    $story = $this->getFeedStory();
    $action = $story->getStoryData()->getValue('action');

    return ($action == DifferentialAction::ACTION_CLOSE) ||
           ($action == DifferentialAction::ACTION_ABANDON);
  }

  public function willPublishStory($object) {
    return id(new DifferentialRevisionQuery())
      ->setViewer($this->getViewer())
      ->withIDs(array($object->getID()))
      ->needRelationships(true)
      ->executeOne();
  }

  public function getOwnerPHID($object) {
    return $object->getAuthorPHID();
  }

  public function getActiveUserPHIDs($object) {
    $status = $object->getStatus();
    if ($status == ArcanistDifferentialRevisionStatus::NEEDS_REVIEW) {
      return $object->getReviewers();
    } else {
      return array();
    }
  }

  public function getPassiveUserPHIDs($object) {
    $status = $object->getStatus();
    if ($status == ArcanistDifferentialRevisionStatus::NEEDS_REVIEW) {
      return array();
    } else {
      return $object->getReviewers();
    }
  }

  public function getCCUserPHIDs($object) {
    return $object->getCCPHIDs();
  }

  public function getObjectTitle($object) {
    $prefix = $this->getTitlePrefix($object);

    $lines = new PhutilNumber($object->getLineCount());
    $lines = pht('[Request, %d lines]', $lines);

    $id = $object->getID();

    $title = $object->getTitle();

    return ltrim("{$prefix} {$lines} D{$id}: {$title}");
  }

  public function getObjectURI($object) {
    return PhabricatorEnv::getProductionURI('/D'.$object->getID());
  }

  public function getObjectDescription($object) {
    return $object->getSummary();
  }

  public function isObjectClosed($object) {
    return $object->isClosed();
  }

  public function getResponsibilityTitle($object) {
    $prefix = $this->getTitlePrefix($object);
    return pht('%s Review Request', $prefix);
  }

  public function getStoryText($object) {
    $implied_context = $this->getRenderWithImpliedContext();

    $story = $this->getFeedStory();
    if ($story instanceof PhabricatorFeedStoryDifferential) {
      $text = $story->renderForAsanaBridge($implied_context);
    } else {
      $text = $story->renderText();
    }
    return $text;
  }

  private function getTitlePrefix(DifferentialRevision $revision) {
    $prefix_key = 'metamta.differential.subject-prefix';
    return PhabricatorEnv::getEnvConfig($prefix_key);
  }

}
