<?php

final class PhabricatorDashboardTransaction
  extends PhabricatorApplicationTransaction {

  const TYPE_NAME = 'dashboard:name';

  public function getApplicationName() {
    return 'dashboard';
  }

  public function getApplicationTransactionType() {
    return PhabricatorDashboardPHIDTypeDashboard::TYPECONST;
  }

  public function getTitle() {
    $author_phid = $this->getAuthorPHID();
    $object_phid = $this->getObjectPHID();

    $old = $this->getOldValue();
    $new = $this->getNewValue();

    $author_link = $this->renderHandleLink($author_phid);

    $type = $this->getTransactionType();
    switch ($type) {
      case self::TYPE_NAME:
        if (!strlen($old)) {
          return pht(
            '%s created this dashboard.',
            $author_link);
        } else {
          return pht(
            '%s renamed this dashboard from "%s" to "%s".',
            $author_link,
            $old,
            $new);
        }
    }

    return parent::getTitle();
  }

  public function getTitleForFeed(PhabricatorFeedStory $story) {
    $author_phid = $this->getAuthorPHID();
    $object_phid = $this->getObjectPHID();

    $old = $this->getOldValue();
    $new = $this->getNewValue();

    $author_link = $this->renderHandleLink($author_phid);
    $object_link = $this->renderHandleLink($object_phid);

    $type = $this->getTransactionType();
    switch ($type) {
      case self::TYPE_NAME:
        if (!strlen($old)) {
          return pht(
            '%s created dashboard %s.',
            $author_link,
            $object_link);
        } else {
          return pht(
            '%s renamed dashboard %s from "%s" to "%s".',
            $author_link,
            $object_link,
            $old,
            $new);
        }
    }

    return parent::getTitleForFeed($story);
  }

  public function getColor() {
    $old = $this->getOldValue();
    $new = $this->getNewValue();

    switch ($this->getTransactionType()) {
      case self::TYPE_NAME:
        if (!strlen($old)) {
          return PhabricatorTransactions::COLOR_GREEN;
        }
        break;
    }

    return parent::getColor();
  }
}
