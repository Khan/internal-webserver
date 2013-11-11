<?php

final class DifferentialRevisionCommentView extends AphrontView {

  private $comment;
  private $handles;
  private $markupEngine;
  private $preview;
  private $inlines;
  private $changesets;
  private $target;
  private $anchorName;
  private $versusDiffID;

  public function setComment($comment) {
    $this->comment = $comment;
    return $this;
  }

  public function setHandles(array $handles) {
    assert_instances_of($handles, 'PhabricatorObjectHandle');
    $this->handles = $handles;
    return $this;
  }

  public function setMarkupEngine(PhabricatorMarkupEngine $markup_engine) {
    $this->markupEngine = $markup_engine;
    return $this;
  }

  public function setPreview($preview) {
    $this->preview = $preview;
    return $this;
  }

  public function setInlineComments(array $inline_comments) {
    assert_instances_of($inline_comments, 'PhabricatorInlineCommentInterface');
    $this->inlines = $inline_comments;
    return $this;
  }

  public function setChangesets(array $changesets) {
    assert_instances_of($changesets, 'DifferentialChangeset');
    // Ship these in sorted by getSortKey() and keyed by ID... or else!
    $this->changesets = $changesets;
    return $this;
  }

  public function setTargetDiff($target) {
    $this->target = $target;
    return $this;
  }

  public function setVersusDiffID($diff_vs) {
    $this->versusDiffID = $diff_vs;
    return $this;
  }

  public function setAnchorName($anchor_name) {
    $this->anchorName = $anchor_name;
    return $this;
  }

  public function render() {

    if (!$this->user) {
      throw new Exception("Call setUser() before rendering!");
    }

    require_celerity_resource('phabricator-remarkup-css');
    require_celerity_resource('differential-revision-comment-css');

    $comment = $this->comment;

    $action = $comment->getAction();

    $action_class = 'differential-comment-action-'.$action;

    $info = array();

    $content = $comment->getContent();
    $hide_comments = true;
    if (strlen(rtrim($content))) {
      $hide_comments = false;

      $content = $this->markupEngine->getOutput(
        $comment,
        PhabricatorInlineCommentInterface::MARKUP_FIELD_BODY);

      $content = phutil_tag_div('phabricator-remarkup', $content);
    }

    $inline_render = $this->renderInlineComments();
    if ($inline_render) {
      $hide_comments = false;
    }

    $author = $this->handles[$comment->getAuthorPHID()];
    $author_link = $author->renderLink();

    $metadata = $comment->getMetadata();
    $added_reviewers = idx(
      $metadata,
      DifferentialComment::METADATA_ADDED_REVIEWERS,
      array());
    $removed_reviewers = idx(
      $metadata,
      DifferentialComment::METADATA_REMOVED_REVIEWERS,
      array());
    $added_ccs = idx(
      $metadata,
      DifferentialComment::METADATA_ADDED_CCS,
      array());

    $verb = DifferentialAction::getActionPastTenseVerb($comment->getAction());

    $actions = array();
    // TODO: i18n
    switch ($comment->getAction()) {
      case DifferentialAction::ACTION_ADDCCS:
        $actions[] = hsprintf(
          "%s added CCs: %s.",
          $author_link,
          $this->renderHandleList($added_ccs));
        $added_ccs = null;
        break;
      case DifferentialAction::ACTION_ADDREVIEWERS:
        $actions[] = hsprintf(
          "%s added reviewers: %s.",
          $author_link,
          $this->renderHandleList($added_reviewers));
        $added_reviewers = null;
        break;
      case DifferentialAction::ACTION_UPDATE:
        $diff_id = idx($metadata, DifferentialComment::METADATA_DIFF_ID);
        if ($diff_id) {
          $diff_link = phutil_tag(
            'a',
            array(
              'href' => '/D'.$comment->getRevisionID().'?id='.$diff_id,
            ),
            'Diff #'.$diff_id);
          $actions[] = hsprintf(
            "%s updated this revision to %s.",
            $author_link,
            $diff_link);
        } else {
          $actions[] = hsprintf(
            "%s %s this revision.",
            $author_link,
            $verb);
        }
        break;
      default:
        $actions[] = hsprintf(
          "%s %s this revision.",
          $author_link,
          $verb);
        break;
    }

    if ($added_reviewers) {
      $actions[] = hsprintf(
        "%s added reviewers: %s.",
        $author_link,
        $this->renderHandleList($added_reviewers));
    }

    if ($removed_reviewers) {
      $actions[] = hsprintf(
        "%s removed reviewers: %s.",
        $author_link,
        $this->renderHandleList($removed_reviewers));
    }

    if ($added_ccs) {
      $actions[] = hsprintf(
        "%s added CCs: %s.",
        $author_link,
        $this->renderHandleList($added_ccs));
    }

    foreach ($actions as $key => $action) {
      $actions[$key] = phutil_tag('div', array(), $action);
    }

    $xaction_view = id(new PhabricatorTransactionView())
      ->setUser($this->user)
      ->setImageURI($author->getImageURI())
      ->setContentSource($comment->getContentSource())
      ->addClass($action_class)
      ->setActions($actions);

    if ($this->preview) {
      $xaction_view->setIsPreview($this->preview);
    } else {
      $xaction_view->setEpoch($comment->getDateCreated());
      if ($this->anchorName) {
        $anchor_text =
          'D'.$comment->getRevisionID().
          '#'.preg_replace('/^comment-/', '', $this->anchorName);

        $xaction_view->setAnchor($this->anchorName, $anchor_text);
      }
    }

    if (!$hide_comments) {
      $xaction_view->appendChild(phutil_tag_div(
        'differential-comment-core',
        array($content, $inline_render)));
    }

    return $xaction_view->render();
  }

  private function renderHandleList(array $phids) {
    $result = array();
    foreach ($phids as $phid) {
      $result[] = $this->handles[$phid]->renderLink();
    }
    return phutil_implode_html(', ', $result);
  }

  private function renderInlineComments() {
    if (!$this->inlines) {
      return null;
    }

    $inlines = $this->inlines;
    $changesets = $this->changesets;
    $inlines_by_changeset = mgroup($inlines, 'getChangesetID');
    $inlines_by_changeset = array_select_keys(
      $inlines_by_changeset,
      array_keys($this->changesets));

    $view = new PhabricatorInlineSummaryView();
    foreach ($inlines_by_changeset as $changeset_id => $inlines) {
      $changeset = $changesets[$changeset_id];
      $items = array();
      foreach ($inlines as $inline) {

        $on_target = ($this->target) &&
                     ($this->target->getID() == $changeset->getDiffID());

        $is_visible = false;
        if ($inline->getIsNewFile()) {
          // This comment is on the right side of the versus diff, and visible
          // on the left side of the page.
          if ($this->versusDiffID) {
            if ($changeset->getDiffID() == $this->versusDiffID) {
              $is_visible = true;
            }
          }

          // This comment is on the right side of the target diff, and visible
          // on the right side of the page.
          if ($on_target) {
            $is_visible = true;
          }
        } else {
          // Ths comment is on the left side of the target diff, and visible
          // on the left side of the page.
          if (!$this->versusDiffID) {
            if ($on_target) {
              $is_visible = true;
            }
          }

          // TODO: We still get one edge case wrong here, when we have a
          // versus diff and the file didn't exist in the old version. The
          // comment is visible because we show the left side of the target
          // diff when there's no corresponding file in the versus diff, but
          // we incorrectly link it off-page.
        }

        $item = array(
          'id'      => $inline->getID(),
          'line'    => $inline->getLineNumber(),
          'length'  => $inline->getLineLength(),
          'content' => $this->markupEngine->getOutput(
            $inline,
            DifferentialInlineComment::MARKUP_FIELD_BODY),
        );

        if (!$is_visible) {
          $diff_id = $changeset->getDiffID();
          $item['where'] = '(On Diff #'.$diff_id.')';
          $item['href'] =
            'D'.$this->comment->getRevisionID().
            '?id='.$diff_id.
            '#inline-'.$inline->getID();
        }

        $items[] = $item;
      }
      $view->addCommentGroup($changeset->getFilename(), $items);
    }

    return $view;
  }

}
