<?php

final class DifferentialCommentEditor extends PhabricatorEditor {

  protected $revision;
  protected $action;

  protected $attachInlineComments;
  protected $message;
  protected $changedByCommit;
  protected $addedReviewers = array();
  protected $removedReviewers = array();
  private $addedCCs = array();

  private $parentMessageID;
  private $contentSource;
  private $noEmail;

  private $isDaemonWorkflow;

  public function __construct(
    DifferentialRevision $revision,
    $action) {

    $this->revision = $revision;
    $this->action   = $action;
  }

  public function setParentMessageID($parent_message_id) {
    $this->parentMessageID = $parent_message_id;
    return $this;
  }

  public function setMessage($message) {
    $this->message = $message;
    return $this;
  }

  public function setAttachInlineComments($attach) {
    $this->attachInlineComments = $attach;
    return $this;
  }

  public function setChangedByCommit($changed_by_commit) {
    $this->changedByCommit = $changed_by_commit;
    return $this;
  }

  public function getChangedByCommit() {
    return $this->changedByCommit;
  }

  public function setAddedReviewers(array $added_reviewers) {
    $this->addedReviewers = $added_reviewers;
    return $this;
  }

  public function getAddedReviewers() {
    return $this->addedReviewers;
  }

  public function setRemovedReviewers(array $removeded_reviewers) {
    $this->removedReviewers = $removeded_reviewers;
    return $this;
  }

  public function getRemovedReviewers() {
    return $this->removedReviewers;
  }

  public function setAddedCCs($added_ccs) {
    $this->addedCCs = $added_ccs;
    return $this;
  }

  public function getAddedCCs() {
    return $this->addedCCs;
  }

  public function setContentSource(PhabricatorContentSource $content_source) {
    $this->contentSource = $content_source;
    return $this;
  }

  public function setIsDaemonWorkflow($is_daemon) {
    $this->isDaemonWorkflow = $is_daemon;
    return $this;
  }

  public function setNoEmail($no_email) {
    $this->noEmail = $no_email;
    return $this;
  }

  public function save() {
    $actor = $this->requireActor();

    // Reload the revision to pick up reviewer status, until we can lift this
    // out of here.
    $this->revision = id(new DifferentialRevisionQuery())
      ->setViewer($actor)
      ->withIDs(array($this->revision->getID()))
      ->needRelationships(true)
      ->needReviewerStatus(true)
      ->needReviewerAuthority(true)
      ->executeOne();

    $revision           = $this->revision;
    $action             = $this->action;
    $actor_phid         = $actor->getPHID();
    $actor_is_author    = ($actor_phid == $revision->getAuthorPHID());
    $allow_self_accept  = PhabricatorEnv::getEnvConfig(
      'differential.allow-self-accept');
    $always_allow_close = PhabricatorEnv::getEnvConfig(
      'differential.always-allow-close');
    $allow_reopen = PhabricatorEnv::getEnvConfig(
      'differential.allow-reopen');
    $revision_status    = $revision->getStatus();
    $update_accepted_status = false;

    $reviewer_phids = $revision->getReviewers();
    if ($reviewer_phids) {
      $reviewer_phids = array_fuse($reviewer_phids);
    }

    $metadata = array();

    $inline_comments = array();
    if ($this->attachInlineComments) {
      $inline_comments = id(new DifferentialInlineCommentQuery())
        ->withDraftComments($actor_phid, $revision->getID())
        ->execute();
    }

    switch ($action) {
      case DifferentialAction::ACTION_COMMENT:
        if (!$this->message && !$inline_comments) {
          throw new DifferentialActionHasNoEffectException(
            "You are submitting an empty comment with no action: ".
            "you must act on the revision or post a comment.");
        }

        // If the actor is a reviewer, and their status is "added" (that is,
        // they haven't accepted or requested changes to the revision),
        // upgrade their status to "commented". If they have a stronger status
        // already, don't overwrite it.
        if (isset($reviewer_phids[$actor_phid])) {
          $status_added = DifferentialReviewerStatus::STATUS_ADDED;
          $reviewer_status = $revision->getReviewerStatus();
          foreach ($reviewer_status as $reviewer) {
            if ($reviewer->getReviewerPHID() == $actor_phid) {
              if ($reviewer->getStatus() == $status_added) {
                DifferentialRevisionEditor::updateReviewerStatus(
                  $revision,
                  $actor,
                  $actor_phid,
                  DifferentialReviewerStatus::STATUS_COMMENTED);
              }
            }
          }
        }

        break;

      case DifferentialAction::ACTION_RESIGN:
        if ($actor_is_author) {
          throw new Exception('You can not resign from your own revision!');
        }
        if (empty($reviewer_phids[$actor_phid])) {
          throw new DifferentialActionHasNoEffectException(
            "You can not resign from this revision because you are not ".
            "a reviewer.");
        }

        list($added_reviewers, $ignored) = $this->alterReviewers();
        if ($added_reviewers) {
          $key = DifferentialComment::METADATA_ADDED_REVIEWERS;
          $metadata[$key] = $added_reviewers;
        }

        DifferentialRevisionEditor::updateReviewers(
          $revision,
          $actor,
          array(),
          array($actor_phid));

        // If you are a blocking reviewer, your presence as a reviewer may be
        // the only thing keeping a revision from transitioning to "accepted".
        // Recalculate state after removing the resigning reviewer.
        switch ($revision_status) {
          case ArcanistDifferentialRevisionStatus::NEEDS_REVISION:
          case ArcanistDifferentialRevisionStatus::NEEDS_REVIEW:
            $update_accepted_status = true;
            break;
        }

        break;

      case DifferentialAction::ACTION_ABANDON:
        if (!$actor_is_author) {
          throw new Exception('You can only abandon your own revisions.');
        }

        if ($revision_status == ArcanistDifferentialRevisionStatus::CLOSED) {
          throw new DifferentialActionHasNoEffectException(
            "You can not abandon this revision because it has already ".
            "been closed.");
        }

        if ($revision_status == ArcanistDifferentialRevisionStatus::ABANDONED) {
          throw new DifferentialActionHasNoEffectException(
            "You can not abandon this revision because it has already ".
            "been abandoned.");
        }

        $revision->setStatus(ArcanistDifferentialRevisionStatus::ABANDONED);
        break;

      case DifferentialAction::ACTION_ACCEPT:
        if ($actor_is_author && !$allow_self_accept) {
          throw new Exception('You can not accept your own revision.');
        }

        switch ($revision_status) {
          case ArcanistDifferentialRevisionStatus::ABANDONED:
            throw new DifferentialActionHasNoEffectException(
              "You can not accept this revision because it has been ".
              "abandoned.");
          case ArcanistDifferentialRevisionStatus::CLOSED:
            throw new DifferentialActionHasNoEffectException(
              "You can not accept this revision because it has already ".
              "been closed.");
          case ArcanistDifferentialRevisionStatus::NEEDS_REVIEW:
          case ArcanistDifferentialRevisionStatus::NEEDS_REVISION:
          case ArcanistDifferentialRevisionStatus::ACCEPTED:
            // We expect "Accept" from these states.
            break;
          default:
            throw new Exception(
              "Unexpected revision state '{$revision_status}'!");
        }

        $was_reviewer_already = false;
        foreach ($revision->getReviewerStatus() as $reviewer) {
          if ($reviewer->hasAuthority($actor)) {
            DifferentialRevisionEditor::updateReviewerStatus(
              $revision,
              $actor,
              $reviewer->getReviewerPHID(),
              DifferentialReviewerStatus::STATUS_ACCEPTED);
            if ($reviewer->getReviewerPHID() == $actor_phid) {
              $was_reviewer_already = true;
            }
          }
        }

        if (!$was_reviewer_already) {
          DifferentialRevisionEditor::updateReviewerStatus(
            $revision,
            $actor,
            $actor_phid,
            DifferentialReviewerStatus::STATUS_ACCEPTED);
        }

        $update_accepted_status = true;
        break;

      case DifferentialAction::ACTION_REQUEST:
        if (!$actor_is_author) {
          throw new Exception('You must own a revision to request review.');
        }

        switch ($revision_status) {
          case ArcanistDifferentialRevisionStatus::ACCEPTED:
          case ArcanistDifferentialRevisionStatus::NEEDS_REVISION:
            $revision->setStatus(
              ArcanistDifferentialRevisionStatus::NEEDS_REVIEW);
            break;
          case ArcanistDifferentialRevisionStatus::NEEDS_REVIEW:
            throw new DifferentialActionHasNoEffectException(
              "You can not request review of this revision because it has ".
              "been abandoned.");
          case ArcanistDifferentialRevisionStatus::ABANDONED:
            throw new DifferentialActionHasNoEffectException(
              "You can not request review of this revision because it has ".
              "been abandoned.");
          case ArcanistDifferentialRevisionStatus::CLOSED:
            throw new DifferentialActionHasNoEffectException(
              "You can not request review of this revision because it has ".
              "already been closed.");
          default:
            throw new Exception(
              "Unexpected revision state '{$revision_status}'!");
        }

        list($added_reviewers, $ignored) = $this->alterReviewers();
        if ($added_reviewers) {
          $key = DifferentialComment::METADATA_ADDED_REVIEWERS;
          $metadata[$key] = $added_reviewers;
        }

        break;

      case DifferentialAction::ACTION_REJECT:
        if ($actor_is_author) {
          throw new Exception(
            'You can not request changes to your own revision.');
        }

        switch ($revision_status) {
          case ArcanistDifferentialRevisionStatus::ACCEPTED:
          case ArcanistDifferentialRevisionStatus::NEEDS_REVISION:
          case ArcanistDifferentialRevisionStatus::NEEDS_REVIEW:
            // We expect rejects from these states.
            break;
          case ArcanistDifferentialRevisionStatus::ABANDONED:
            throw new DifferentialActionHasNoEffectException(
              "You can not request changes to this revision because it has ".
              "been abandoned.");
          case ArcanistDifferentialRevisionStatus::CLOSED:
            throw new DifferentialActionHasNoEffectException(
              "You can not request changes to this revision because it has ".
              "already been closed.");
          default:
            throw new Exception(
              "Unexpected revision state '{$revision_status}'!");
        }

        DifferentialRevisionEditor::updateReviewerStatus(
          $revision,
          $actor,
          $actor_phid,
          DifferentialReviewerStatus::STATUS_REJECTED);

        $revision
          ->setStatus(ArcanistDifferentialRevisionStatus::NEEDS_REVISION);
        break;

      case DifferentialAction::ACTION_RETHINK:
        if (!$actor_is_author) {
          throw new Exception(
            "You can not plan changes to somebody else's revision");
        }

        switch ($revision_status) {
          case ArcanistDifferentialRevisionStatus::ACCEPTED:
          case ArcanistDifferentialRevisionStatus::NEEDS_REVISION:
          case ArcanistDifferentialRevisionStatus::NEEDS_REVIEW:
            // We expect accepts from these states.
            break;
          case ArcanistDifferentialRevisionStatus::ABANDONED:
            throw new DifferentialActionHasNoEffectException(
              "You can not plan changes to this revision because it has ".
              "been abandoned.");
          case ArcanistDifferentialRevisionStatus::CLOSED:
            throw new DifferentialActionHasNoEffectException(
              "You can not plan changes to this revision because it has ".
              "already been closed.");
          default:
            throw new Exception(
              "Unexpected revision state '{$revision_status}'!");
        }

        $revision
          ->setStatus(ArcanistDifferentialRevisionStatus::NEEDS_REVISION);
        break;

      case DifferentialAction::ACTION_RECLAIM:
        if (!$actor_is_author) {
          throw new Exception('You can not reclaim a revision you do not own.');
        }


        if ($revision_status != ArcanistDifferentialRevisionStatus::ABANDONED) {
          throw new DifferentialActionHasNoEffectException(
            "You can not reclaim this revision because it is not abandoned.");
        }

        $revision
          ->setStatus(ArcanistDifferentialRevisionStatus::NEEDS_REVIEW);

        $update_accepted_status = true;
        break;

      case DifferentialAction::ACTION_CLOSE:

        // NOTE: The daemons can mark things closed from any state. We treat
        // them as completely authoritative.

        if (!$this->isDaemonWorkflow) {
          if (!$actor_is_author && !$always_allow_close) {
            throw new Exception(
              "You can not mark a revision you don't own as closed.");
          }

          $status_closed = ArcanistDifferentialRevisionStatus::CLOSED;
          $status_accepted = ArcanistDifferentialRevisionStatus::ACCEPTED;

          if ($revision_status == $status_closed) {
            throw new DifferentialActionHasNoEffectException(
              "You can not mark this revision as closed because it has ".
              "already been marked as closed.");
          }

          if ($revision_status != $status_accepted) {
            throw new DifferentialActionHasNoEffectException(
              "You can not mark this revision as closed because it is ".
              "has not been accepted.");
          }
        }

        if (!$revision->getDateCommitted()) {
          $revision->setDateCommitted(time());
        }

        $revision->setStatus(ArcanistDifferentialRevisionStatus::CLOSED);
        break;

      case DifferentialAction::ACTION_REOPEN:
        if (!$allow_reopen) {
          throw new Exception(
            "You cannot reopen a revision when this action is disabled.");
        }

        if ($revision_status != ArcanistDifferentialRevisionStatus::CLOSED) {
          throw new Exception(
            "You cannot reopen a revision that is not currently closed.");
        }

        $revision->setStatus(ArcanistDifferentialRevisionStatus::NEEDS_REVIEW);

        break;

      case DifferentialAction::ACTION_ADDREVIEWERS:
        list($added_reviewers, $ignored) = $this->alterReviewers();

        if ($added_reviewers) {
          $key = DifferentialComment::METADATA_ADDED_REVIEWERS;
          $metadata[$key] = $added_reviewers;
        } else {
          $user_tried_to_add = count($this->getAddedReviewers());
          if ($user_tried_to_add == 0) {
            throw new DifferentialActionHasNoEffectException(
              "You can not add reviewers, because you did not specify any ".
              "reviewers.");
          } else if ($user_tried_to_add == 1) {
            throw new DifferentialActionHasNoEffectException(
              "You can not add that reviewer, because they are already an ".
              "author or reviewer.");
          } else {
            throw new DifferentialActionHasNoEffectException(
              "You can not add those reviewers, because they are all already ".
              "authors or reviewers.");
          }
        }

        break;
      case DifferentialAction::ACTION_ADDCCS:
        $added_ccs = $this->getAddedCCs();
        $user_tried_to_add = count($added_ccs);

        $added_ccs = $this->filterAddedCCs($added_ccs);

        if ($added_ccs) {
          $key = DifferentialComment::METADATA_ADDED_CCS;
          $metadata[$key] = $added_ccs;
        } else {
          if ($user_tried_to_add == 0) {
            throw new DifferentialActionHasNoEffectException(
              "You can not add CCs, because you did not specify any ".
              "CCs.");
          } else if ($user_tried_to_add == 1) {
            throw new DifferentialActionHasNoEffectException(
              "You can not add that CC, because they are already an ".
              "author, reviewer or CC.");
          } else {
            throw new DifferentialActionHasNoEffectException(
              "You can not add those CCs, because they are all already ".
              "authors, reviewers or CCs.");
          }
        }
        break;
      case DifferentialAction::ACTION_CLAIM:
        if ($actor_is_author) {
          throw new Exception("You can not commandeer your own revision.");
        }

        switch ($revision_status) {
          case ArcanistDifferentialRevisionStatus::CLOSED:
            throw new DifferentialActionHasNoEffectException(
              "You can not commandeer this revision because it has ".
              "already been closed.");
            break;
        }

        $this->setAddedReviewers(array($revision->getAuthorPHID()));
        $this->setRemovedReviewers(array($actor_phid));

        // NOTE: Set the new author PHID before calling addReviewers(), since it
        // doesn't permit the author to become a reviewer.
        $revision->setAuthorPHID($actor_phid);

        list($added_reviewers, $removed_reviewers) = $this->alterReviewers();
        if ($added_reviewers) {
          $key = DifferentialComment::METADATA_ADDED_REVIEWERS;
          $metadata[$key] = $added_reviewers;
        }

        if ($removed_reviewers) {
          $key = DifferentialComment::METADATA_REMOVED_REVIEWERS;
          $metadata[$key] = $removed_reviewers;
        }

        break;
      default:
        throw new Exception('Unsupported action.');
    }

    // Update information about reviewer in charge.
    if ($action == DifferentialAction::ACTION_ACCEPT ||
        $action == DifferentialAction::ACTION_REJECT) {
      $revision->setLastReviewerPHID($actor_phid);
    }

    // TODO: Call beginReadLocking() prior to loading the revision.
    $revision->openTransaction();

    // Always save the revision (even if we didn't actually change any of its
    // properties) so that it jumps to the top of the revision list when sorted
    // by "updated". Notably, this allows "ping" comments to push it to the
    // top of the action list.
    $revision->save();

    if ($update_accepted_status) {
      $revision = DifferentialRevisionEditor::updateAcceptedStatus(
        $actor,
        $revision);
    }

    if ($action != DifferentialAction::ACTION_RESIGN) {
      DifferentialRevisionEditor::addCC(
        $revision,
        $actor_phid,
        $actor_phid);
    }

    $is_new = !$revision->getID();

    $event = new PhabricatorEvent(
      PhabricatorEventType::TYPE_DIFFERENTIAL_WILLEDITREVISION,
        array(
          'revision'      => $revision,
          'new'           => $is_new,
        ));

    $event->setUser($actor);
    PhutilEventEngine::dispatchEvent($event);

    $template = id(new DifferentialComment())
      ->setAuthorPHID($actor_phid)
      ->setRevision($revision);

    if ($this->contentSource) {
      $content_source = $this->contentSource;
    } else {
      $content_source = PhabricatorContentSource::newForSource(
        PhabricatorContentSource::SOURCE_LEGACY,
        array());
    }

    $template->setContentSource($content_source);

    $comments = array();

    // If this edit performs a meaningful action, save a transaction for the
    // action. Do this first, since the mail currently assumes the first
    // transaction is the strongest.
    if ($action != DifferentialAction::ACTION_COMMENT &&
        $action != DifferentialAction::ACTION_ADDREVIEWERS &&
        $action != DifferentialAction::ACTION_ADDCCS) {
      $comments[] = id(clone $template)
        ->setAction($action);
    }

    // If this edit adds reviewers, save a transaction for those changes.
    $rev_add = DifferentialComment::METADATA_ADDED_REVIEWERS;
    $rev_rem = DifferentialComment::METADATA_REMOVED_REVIEWERS;
    if (idx($metadata, $rev_add) || idx($metadata, $rev_rem)) {
      $reviewer_meta = array_select_keys($metadata, array($rev_add, $rev_rem));

      $comments[] = id(clone $template)
        ->setAction(DifferentialAction::ACTION_ADDREVIEWERS)
        ->setMetadata($reviewer_meta);
    }

    // If this edit adds CCs, save a transaction for the new CCs. We build this
    // for either explicit CCs, or for @mentions. First, find any "@mentions" in
    // the comment blocks.
    $content_blocks = array($this->message);
    foreach ($inline_comments as $inline) {
      $content_blocks[] = $inline->getContent();
    }
    $mention_ccs = PhabricatorMarkupEngine::extractPHIDsFromMentions(
      $content_blocks);

    // Now, build a comment if we have explicit action CCs or mention CCs.
    $cc_add = DifferentialComment::METADATA_ADDED_CCS;
    if (idx($metadata, $cc_add) || $mention_ccs) {
      $all_adds = array_merge(
        idx($metadata, $cc_add, array()),
        $mention_ccs);
      $all_adds = $this->filterAddedCCs($all_adds);
      if ($all_adds) {
        $cc_meta = array(
          DifferentialComment::METADATA_ADDED_CCS => $all_adds,
        );

        foreach ($all_adds as $cc_phid) {
          DifferentialRevisionEditor::addCC(
            $revision,
            $cc_phid,
            $actor_phid);
        }

        $comments[] = id(clone $template)
          ->setAction(DifferentialAction::ACTION_ADDCCS)
          ->setMetadata($cc_meta);
      }
    }

    // If this edit has comments or inline comments, save a transaction for
    // the comment content.
    if (strlen($this->message)) {
      $comments[] = id(clone $template)
        ->setAction(DifferentialAction::ACTION_COMMENT)
        ->setContent((string)$this->message);
    }

    foreach ($comments as $comment) {
      $comment->save();
    }

    $changesets = array();
    $mail_inlines = array();
    if ($inline_comments) {
      $load_ids = mpull($inline_comments, 'getChangesetID');
      if ($load_ids) {
        $load_ids = array_unique($load_ids);
        $changesets = id(new DifferentialChangeset())->loadAllWhere(
          'id in (%Ld)',
          $load_ids);
      }
      foreach ($inline_comments as $inline) {
        $inline_xaction_comment = $inline->getTransactionCommentForSave();
        $inline_xaction_comment->setRevisionPHID($revision->getPHID());

        $inline_xaction = id(clone $template)
          ->setAction(DifferentialTransaction::TYPE_INLINE)
          ->setProxyComment($inline_xaction_comment)
          ->save();

        $comments[] = $inline_xaction;
        $mail_inlines[] = $inline_xaction->getProxyTransaction();
      }
    }

    $revision->saveTransaction();

    $phids = array($actor_phid);
    $handles = id(new PhabricatorHandleQuery())
      ->setViewer($actor)
      ->withPHIDs($phids)
      ->execute();
    $actor_handle = $handles[$actor_phid];

    $xherald_header = HeraldTranscript::loadXHeraldRulesHeader(
      $revision->getPHID());

    $mailed_phids = array();
    if (!$this->noEmail) {
      $mail = id(new DifferentialCommentMail(
        $revision,
        $actor_handle,
        $comments,
        $changesets,
        $mail_inlines))
        ->setActor($actor)
        ->setExcludeMailRecipientPHIDs($this->getExcludeMailRecipientPHIDs())
        ->setToPHIDs(
          array_merge(
            $revision->getReviewers(),
            array($revision->getAuthorPHID())))
        ->setCCPHIDs($revision->getCCPHIDs())
        ->setChangedByCommit($this->getChangedByCommit())
        ->setXHeraldRulesHeader($xherald_header)
        ->setParentMessageID($this->parentMessageID)
        ->send();

      $mailed_phids = $mail->getRawMail()->buildRecipientList();
    }


    $event_data = array(
      'revision_id'          => $revision->getID(),
      'revision_phid'        => $revision->getPHID(),
      'revision_name'        => $revision->getTitle(),
      'revision_author_phid' => $revision->getAuthorPHID(),
      'action'               => head($comments)->getAction(),
      'feedback_content'     => $this->message,
      'actor_phid'           => $actor_phid,

      // NOTE: Don't use this, it will be removed after ApplicationTransactions.
      // For now, it powers inline comment rendering over the Asana brdige.
      'temporaryTransactionPHIDs' => mpull($comments, 'getPHID'),
    );

    id(new PhabricatorFeedStoryPublisher())
      ->setStoryType('PhabricatorFeedStoryDifferential')
      ->setStoryData($event_data)
      ->setStoryTime(time())
      ->setStoryAuthorPHID($actor_phid)
      ->setRelatedPHIDs(
        array(
          $revision->getPHID(),
          $actor_phid,
          $revision->getAuthorPHID(),
        ))
      ->setPrimaryObjectPHID($revision->getPHID())
      ->setSubscribedPHIDs(
        array_merge(
          array($revision->getAuthorPHID()),
          $revision->getReviewers(),
          $revision->getCCPHIDs()))
      ->setMailRecipientPHIDs($mailed_phids)
      ->publish();

    id(new PhabricatorSearchIndexer())
      ->queueDocumentForIndexing($revision->getPHID());
  }

  private function filterAddedCCs(array $ccs) {
    $revision = $this->revision;

    $current_ccs = $revision->getCCPHIDs();
    $current_ccs = array_fill_keys($current_ccs, true);

    $reviewer_phids = $revision->getReviewers();
    $reviewer_phids = array_fill_keys($reviewer_phids, true);

    foreach ($ccs as $key => $cc) {
      if (isset($current_ccs[$cc])) {
        unset($ccs[$key]);
      }
      if (isset($reviewer_phids[$cc])) {
        unset($ccs[$key]);
      }
      if ($cc == $revision->getAuthorPHID()) {
        unset($ccs[$key]);
      }
    }

    return $ccs;
  }

  private function alterReviewers() {
    $actor_phid        = $this->getActor()->getPHID();
    $revision          = $this->revision;
    $added_reviewers   = $this->getAddedReviewers();
    $removed_reviewers = $this->getRemovedReviewers();
    $reviewer_phids    = $revision->getReviewers();
    $allow_self_accept = PhabricatorEnv::getEnvConfig(
      'differential.allow-self-accept');

    $reviewer_phids_map = array_fill_keys($reviewer_phids, true);
    foreach ($added_reviewers as $k => $user_phid) {
      if (!$allow_self_accept && $user_phid == $revision->getAuthorPHID()) {
        unset($added_reviewers[$k]);
      }
      if (isset($reviewer_phids_map[$user_phid])) {
        unset($added_reviewers[$k]);
      }
    }

    foreach ($removed_reviewers as $k => $user_phid) {
      if (!isset($reviewer_phids_map[$user_phid])) {
        unset($removed_reviewers[$k]);
      }
    }

    $added_reviewers = array_unique($added_reviewers);
    $removed_reviewers = array_unique($removed_reviewers);

    if ($added_reviewers) {
      DifferentialRevisionEditor::updateReviewers(
        $revision,
        $this->getActor(),
        $added_reviewers,
        $removed_reviewers);
    }

    return array($added_reviewers, $removed_reviewers);
  }

}
