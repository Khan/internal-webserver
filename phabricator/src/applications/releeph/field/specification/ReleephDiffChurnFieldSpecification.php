<?php

final class ReleephDiffChurnFieldSpecification
  extends ReleephFieldSpecification {

  const REJECTIONS_WEIGHT =  30;
  const COMMENTS_WEIGHT   =   7;
  const UPDATES_WEIGHT    =  10;
  const MAX_POINTS        = 100;

  public function getFieldKey() {
    return 'churn';
  }

  public function getName() {
    return 'Churn';
  }

  public function renderValueForHeaderView() {
    $diff_rev = $this->getReleephRequest()->loadDifferentialRevision();
    if (!$diff_rev) {
      return null;
    }

    $diff_rev = $this->getReleephRequest()->loadDifferentialRevision();

    $xactions = id(new DifferentialTransactionQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withObjectPHIDs(array($diff_rev->getPHID()))
      ->execute();

    $rejections = 0;
    $comments = 0;
    $updates = 0;
    foreach ($xactions as $xaction) {
      switch ($xaction->getTransactionType()) {
        case PhabricatorTransactions::TYPE_COMMENT:
          $comments++;
          break;
        case DifferentialTransaction::TYPE_UPDATE:
          $updates++;
          break;
        case DifferentialTransaction::TYPE_ACTION:
          switch ($xaction->getNewValue()) {
            case DifferentialAction::ACTION_REJECT:
              $rejections++;
              break;
          }
          break;
      }
    }

    $points =
      self::REJECTIONS_WEIGHT * $rejections +
      self::COMMENTS_WEIGHT * $comments +
      self::UPDATES_WEIGHT * $updates;

    if ($points === 0) {
      $points = 0.15 * self::MAX_POINTS;
      $blurb = 'Silent diff';
    } else {
      $parts = array();
      if ($rejections) {
        $parts[] = pht('%d rejection(s)', $rejections);
      }
      if ($comments) {
        $parts[] = pht('%d comment(s)', $comments);
      }
      if ($updates) {
        $parts[] = pht('%d update(s)', $updates);
      }

      if (count($parts) === 0) {
        $blurb = '';
      } else if (count($parts) === 1) {
        $blurb = head($parts);
      } else {
        $last = array_pop($parts);
        $blurb = implode(', ', $parts).' and '.$last;
      }
    }

    return id(new AphrontProgressBarView())
      ->setValue($points)
      ->setMax(self::MAX_POINTS)
      ->setCaption($blurb)
      ->render();
  }

}
