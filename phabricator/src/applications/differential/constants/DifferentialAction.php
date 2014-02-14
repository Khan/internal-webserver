<?php

final class DifferentialAction {

  const ACTION_CLOSE          = 'commit';
  const ACTION_COMMENT        = 'none';
  const ACTION_ACCEPT         = 'accept';
  const ACTION_REJECT         = 'reject';
  const ACTION_RETHINK        = 'rethink';
  const ACTION_ABANDON        = 'abandon';
  const ACTION_REQUEST        = 'request_review';
  const ACTION_RECLAIM        = 'reclaim';
  const ACTION_UPDATE         = 'update';
  const ACTION_RESIGN         = 'resign';
  const ACTION_SUMMARIZE      = 'summarize';
  const ACTION_TESTPLAN       = 'testplan';
  const ACTION_CREATE         = 'create';
  const ACTION_ADDREVIEWERS   = 'add_reviewers';
  const ACTION_ADDCCS         = 'add_ccs';
  const ACTION_CLAIM          = 'claim';
  const ACTION_REOPEN         = 'reopen';

  public static function getBasicStoryText($action, $author_name) {
    switch ($action) {
        case DifferentialAction::ACTION_COMMENT:
          $title = pht('%s commented on this revision.',
            $author_name);
        break;
        case DifferentialAction::ACTION_ACCEPT:
          $title = pht('%s accepted this revision.',
            $author_name);
        break;
        case DifferentialAction::ACTION_REJECT:
          $title = pht('%s requested changes to this revision.',
            $author_name);
        break;
        case DifferentialAction::ACTION_RETHINK:
          $title = pht('%s planned changes to this revision.',
            $author_name);
        break;
        case DifferentialAction::ACTION_ABANDON:
          $title = pht('%s abandoned this revision.',
            $author_name);
        break;
        case DifferentialAction::ACTION_CLOSE:
          $title = pht('%s closed this revision.',
            $author_name);
        break;
        case DifferentialAction::ACTION_REQUEST:
          $title = pht('%s requested a review of this revision.',
            $author_name);
        break;
        case DifferentialAction::ACTION_RECLAIM:
          $title = pht('%s reclaimed this revision.',
            $author_name);
        break;
        case DifferentialAction::ACTION_UPDATE:
          $title = pht('%s updated this revision.',
            $author_name);
        break;
        case DifferentialAction::ACTION_RESIGN:
          $title = pht('%s resigned from this revision.',
            $author_name);
        break;
        case DifferentialAction::ACTION_SUMMARIZE:
          $title = pht('%s summarized this revision.',
            $author_name);
        break;
        case DifferentialAction::ACTION_TESTPLAN:
          $title = pht('%s explained the test plan for this revision.',
            $author_name);
        break;
        case DifferentialAction::ACTION_CREATE:
          $title = pht('%s created this revision.',
            $author_name);
        break;
        case DifferentialAction::ACTION_ADDREVIEWERS:
          $title = pht('%s added reviewers to this revision.',
            $author_name);
        break;
        case DifferentialAction::ACTION_ADDCCS:
          $title = pht('%s added CCs to this revision.',
            $author_name);
        break;
        case DifferentialAction::ACTION_CLAIM:
          $title = pht('%s commandeered this revision.',
            $author_name);
        break;
        case DifferentialAction::ACTION_REOPEN:
          $title = pht('%s reopened this revision.',
            $author_name);
        break;
        case DifferentialTransaction::TYPE_INLINE:
          $title = pht(
            '%s added an inline comment.',
            $author_name);
          break;
        default:
          $title = pht('Ghosts happened to this revision.');
        break;
      }
    return $title;
  }

  /* legacy, for just mail now */
  public static function getActionPastTenseVerb($action) {
    $verbs = array(
      self::ACTION_COMMENT        => 'commented on',
      self::ACTION_ACCEPT         => 'accepted',
      self::ACTION_REJECT         => 'requested changes to',
      self::ACTION_RETHINK        => 'planned changes to',
      self::ACTION_ABANDON        => 'abandoned',
      self::ACTION_CLOSE          => 'closed',
      self::ACTION_REQUEST        => 'requested a review of',
      self::ACTION_RECLAIM        => 'reclaimed',
      self::ACTION_UPDATE         => 'updated',
      self::ACTION_RESIGN         => 'resigned from',
      self::ACTION_SUMMARIZE      => 'summarized',
      self::ACTION_TESTPLAN       => 'explained the test plan for',
      self::ACTION_CREATE         => 'created',
      self::ACTION_ADDREVIEWERS   => 'added reviewers to',
      self::ACTION_ADDCCS         => 'added CCs to',
      self::ACTION_CLAIM          => 'commandeered',
      self::ACTION_REOPEN         => 'reopened',
      DifferentialTransaction::TYPE_INLINE => 'commented on',
    );

    if (!empty($verbs[$action])) {
      return $verbs[$action];
    } else {
      return 'brazenly "'.$action.'ed"';
    }
  }

  public static function getActionVerb($action) {
    $verbs = array(
      self::ACTION_COMMENT        => pht('Comment'),
      self::ACTION_ACCEPT         => pht("Accept Revision \xE2\x9C\x94"),
      self::ACTION_REJECT         => pht("Request Changes \xE2\x9C\x98"),
      self::ACTION_RETHINK        => pht("Plan Changes \xE2\x9C\x98"),
      self::ACTION_ABANDON        => pht('Abandon Revision'),
      self::ACTION_REQUEST        => pht('Request Review'),
      self::ACTION_RECLAIM        => pht('Reclaim Revision'),
      self::ACTION_RESIGN         => pht('Resign as Reviewer'),
      self::ACTION_ADDREVIEWERS   => pht('Add Reviewers'),
      self::ACTION_ADDCCS         => pht('Add CCs'),
      self::ACTION_CLOSE          => pht('Close Revision'),
      self::ACTION_CLAIM          => pht('Commandeer Revision'),
      self::ACTION_REOPEN         => pht('Reopen'),
    );

    if (!empty($verbs[$action])) {
      return $verbs[$action];
    } else {
      return 'brazenly '.$action;
    }
  }

  public static function allowReviewers($action) {
    if ($action == DifferentialAction::ACTION_ADDREVIEWERS ||
        $action == DifferentialAction::ACTION_REQUEST ||
        $action == DifferentialAction::ACTION_RESIGN) {
      return true;
    }
    return false;
  }

}
