<?php

final class DifferentialTransaction extends PhabricatorApplicationTransaction {

  const TYPE_INLINE = 'differential:inline';
  const TYPE_UPDATE = 'differential:update';
  const TYPE_ACTION = 'differential:action';
  const TYPE_STATUS = 'differential:status';

  public function getApplicationName() {
    return 'differential';
  }

  public function getApplicationTransactionType() {
    return DifferentialPHIDTypeRevision::TYPECONST;
  }

  public function getApplicationTransactionCommentObject() {
    return new DifferentialTransactionComment();
  }

  public function shouldHide() {
    switch ($this->getTransactionType()) {
      case PhabricatorTransactions::TYPE_EDGE:
        $old = $this->getOldValue();
        $new = $this->getNewValue();
        $add = array_diff_key($new, $old);
        $rem = array_diff_key($old, $new);

        // Hide metadata-only edge transactions. These correspond to users
        // accepting or rejecting revisions, but the change is always explicit
        // because of the TYPE_ACTION transaction. Rendering these transactions
        // just creates clutter.

        if (!$add && !$rem) {
          return true;
        }
        break;
    }

    return false;
  }

  public function shouldHideForMail(array $xactions) {
    switch ($this->getTransactionType()) {
      case self::TYPE_INLINE:
        // Hide inlines when rendering mail transactions if any other
        // transaction type exists.
        foreach ($xactions as $xaction) {
          if ($xaction->getTransactionType() != self::TYPE_INLINE) {
            return true;
          }
        }

        // If only inline transactions exist, we just render the first one.
        return ($this !== head($xactions));
    }

    return $this->shouldHide();
  }

  public function getBodyForMail() {
    switch ($this->getTransactionType()) {
      case self::TYPE_INLINE:
        // Don't render inlines into the mail body; they render into a special
        // section immediately after the body instead.
        return null;
    }

    return parent::getBodyForMail();
  }


  public function getTitle() {
    $author_phid = $this->getAuthorPHID();
    $author_handle = $this->renderHandleLink($author_phid);

    $old = $this->getOldValue();
    $new = $this->getNewValue();

    switch ($this->getTransactionType()) {
      case self::TYPE_INLINE:
        return pht(
          '%s added inline comments.',
          $author_handle);
      case self::TYPE_UPDATE:
        if ($new) {
          // TODO: Migrate to PHIDs and use handles here?
          // TODO: Link this?
          return pht(
            '%s updated this revision to Diff #%d.',
            $author_handle,
            $new);
        } else {
          return pht(
            '%s updated this revision.',
            $author_handle);
        }
      case self::TYPE_ACTION:
        return DifferentialAction::getBasicStoryText($new, $author_handle);
      case self::TYPE_STATUS:
        switch ($this->getNewValue()) {
          case ArcanistDifferentialRevisionStatus::ACCEPTED:
            return pht(
              'This revision is now accepted and ready to land.');
          case ArcanistDifferentialRevisionStatus::NEEDS_REVISION:
            return pht(
              'This revision now requires changes to proceed.');
          case ArcanistDifferentialRevisionStatus::NEEDS_REVIEW:
            return pht(
              'This revision now requires review to proceed.');
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

    switch ($this->getTransactionType()) {
      case self::TYPE_INLINE:
        return pht(
          '%s added inline comments to %s.',
          $author_link,
          $object_link);
      case self::TYPE_UPDATE:
        return pht(
          '%s updated the diff for %s.',
          $author_link,
          $object_link);
      case self::TYPE_ACTION:
        switch ($new) {
          case DifferentialAction::ACTION_ACCEPT:
            return pht(
              '%s accepted %s.',
              $author_link,
              $object_link);
          case DifferentialAction::ACTION_REJECT:
            return pht(
              '%s requested changes to %s.',
              $author_link,
              $object_link);
          case DifferentialAction::ACTION_RETHINK:
            return pht(
              '%s planned changes to %s.',
              $author_link,
              $object_link);
          case DifferentialAction::ACTION_ABANDON:
            return pht(
              '%s abandoned %s.',
              $author_link,
              $object_link);
          case DifferentialAction::ACTION_CLOSE:
            return pht(
              '%s closed %s.',
              $author_link,
              $object_link);
          case DifferentialAction::ACTION_REQUEST:
            return pht(
              '%s requested review of %s.',
              $author_link,
              $object_link);
          case DifferentialAction::ACTION_RECLAIM:
            return pht(
              '%s reclaimed %s.',
              $author_link,
              $object_link);
          case DifferentialAction::ACTION_RESIGN:
            return pht(
              '%s resigned from %s.',
              $author_link,
              $object_link);
          case DifferentialAction::ACTION_CLAIM:
            return pht(
              '%s commandeered %s.',
              $author_link,
              $object_link);
          case DifferentialAction::ACTION_REOPEN:
            return pht(
              '%s reopened %s.',
              $author_link,
              $object_link);
        }
        break;
      case self::TYPE_STATUS:
        switch ($this->getNewValue()) {
          case ArcanistDifferentialRevisionStatus::ACCEPTED:
            return pht(
              '%s is now accepted and ready to land.',
              $object_link);
          case ArcanistDifferentialRevisionStatus::NEEDS_REVISION:
            return pht(
              '%s now requires changes to proceed.',
              $object_link);
          case ArcanistDifferentialRevisionStatus::NEEDS_REVIEW:
            return pht(
              '%s now requires review to proceed.',
              $object_link);
        }
    }

    return parent::getTitleForFeed($story);
  }

  public function getIcon() {
    switch ($this->getTransactionType()) {
      case self::TYPE_INLINE:
        return 'comment';
      case self::TYPE_UPDATE:
        return 'refresh';
      case self::TYPE_STATUS:
        switch ($this->getNewValue()) {
          case ArcanistDifferentialRevisionStatus::ACCEPTED:
            return 'enable';
          case ArcanistDifferentialRevisionStatus::NEEDS_REVISION:
            return 'delete';
          case ArcanistDifferentialRevisionStatus::NEEDS_REVIEW:
            return 'refresh';
        }
        break;
      case self::TYPE_ACTION:
        switch ($this->getNewValue()) {
          case DifferentialAction::ACTION_CLOSE:
            return 'ok';
          case DifferentialAction::ACTION_ACCEPT:
            return 'enable';
          case DifferentialAction::ACTION_REJECT:
          case DifferentialAction::ACTION_ABANDON:
            return 'delete';
          case DifferentialAction::ACTION_RETHINK:
            return 'disable';
          case DifferentialAction::ACTION_REQUEST:
            return 'refresh';
          case DifferentialAction::ACTION_RECLAIM:
          case DifferentialAction::ACTION_REOPEN:
            return 'new';
          case DifferentialAction::ACTION_RESIGN:
            return 'undo';
          case DifferentialAction::ACTION_CLAIM:
            return 'user';
        }
    }

    return parent::getIcon();
  }

  public function shouldDisplayGroupWith(array $group) {

    // Never group status changes with other types of actions, they're indirect
    // and don't make sense when combined with direct actions.

    $type_status = self::TYPE_STATUS;

    if ($this->getTransactionType() == $type_status) {
      return false;
    }

    foreach ($group as $xaction) {
      if ($xaction->getTransactionType() == $type_status) {
        return false;
      }
    }

    return parent::shouldDisplayGroupWith($group);
  }


  public function getColor() {
    switch ($this->getTransactionType()) {
      case self::TYPE_UPDATE:
        return PhabricatorTransactions::COLOR_SKY;
      case self::TYPE_STATUS:
        switch ($this->getNewValue()) {
          case ArcanistDifferentialRevisionStatus::ACCEPTED:
            return PhabricatorTransactions::COLOR_GREEN;
          case ArcanistDifferentialRevisionStatus::NEEDS_REVISION:
            return PhabricatorTransactions::COLOR_RED;
          case ArcanistDifferentialRevisionStatus::NEEDS_REVIEW:
            return PhabricatorTransactions::COLOR_ORANGE;
        }
        break;
      case self::TYPE_ACTION:
        switch ($this->getNewValue()) {
          case DifferentialAction::ACTION_CLOSE:
            return PhabricatorTransactions::COLOR_BLUE;
          case DifferentialAction::ACTION_ACCEPT:
            return PhabricatorTransactions::COLOR_GREEN;
          case DifferentialAction::ACTION_REJECT:
            return PhabricatorTransactions::COLOR_RED;
          case DifferentialAction::ACTION_ABANDON:
            return PhabricatorTransactions::COLOR_BLACK;
          case DifferentialAction::ACTION_RETHINK:
            return PhabricatorTransactions::COLOR_RED;
          case DifferentialAction::ACTION_REQUEST:
            return PhabricatorTransactions::COLOR_SKY;
          case DifferentialAction::ACTION_RECLAIM:
            return PhabricatorTransactions::COLOR_SKY;
          case DifferentialAction::ACTION_REOPEN:
            return PhabricatorTransactions::COLOR_SKY;
          case DifferentialAction::ACTION_RESIGN:
            return PhabricatorTransactions::COLOR_ORANGE;
          case DifferentialAction::ACTION_CLAIM:
            return PhabricatorTransactions::COLOR_YELLOW;
        }
    }


    return parent::getColor();
  }

  public function getNoEffectDescription() {
    switch ($this->getTransactionType()) {
      case PhabricatorTransactions::TYPE_EDGE:
        switch ($this->getMetadataValue('edge:type')) {
          case PhabricatorEdgeConfig::TYPE_DREV_HAS_REVIEWER:
            return pht(
              'The reviewers you are trying to add are already reviewing '.
              'this revision.');
        }
        break;
      case DifferentialTransaction::TYPE_ACTION:
        switch ($this->getNewValue()) {
          case DifferentialAction::ACTION_CLOSE:
            return pht('This revision is already closed.');
          case DifferentialAction::ACTION_ABANDON:
            return pht('This revision has already been abandoned.');
          case DifferentialAction::ACTION_RECLAIM:
            return pht(
              'You can not reclaim this revision because his revision is '.
              'not abandoned.');
          case DifferentialAction::ACTION_REOPEN:
            return pht(
              'You can not reopen this revision because this revision is '.
              'not closed.');
          case DifferentialAction::ACTION_RETHINK:
            return pht('This revision already requires changes.');
          case DifferentialAction::ACTION_REQUEST:
            return pht('Review is already requested for this revision.');
          case DifferentialAction::ACTION_RESIGN:
            return pht(
              'You can not resign from this revision because you are not '.
              'a reviewer.');
          case DifferentialAction::ACTION_CLAIM:
            return pht(
              'You can not commandeer this revision because you already own '.
              'it.');
          case DifferentialAction::ACTION_ACCEPT:
            return pht(
              'You have already accepted this revision.');
          case DifferentialAction::ACTION_REJECT:
            return pht(
              'You have already requested changes to this revision.');
        }
        break;
    }

    return parent::getNoEffectDescription();
  }


}
