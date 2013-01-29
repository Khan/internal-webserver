<?php

/**
 * @task mail   Sending Mail
 * @task feed   Publishing Feed Stories
 * @task search Search Index
 */
abstract class PhabricatorApplicationTransactionEditor
  extends PhabricatorEditor {

  private $contentSource;
  private $object;
  private $xactions;

  private $isNewObject;
  private $mentionedPHIDs;
  private $continueOnNoEffect;
  private $parentMessageID;

  private $isPreview;

  /**
   * When the editor tries to apply transactions that have no effect, should
   * it raise an exception (default) or drop them and continue?
   *
   * Generally, you will set this flag for edits coming from "Edit" interfaces,
   * and leave it cleared for edits coming from "Comment" interfaces, so the
   * user will get a useful error if they try to submit a comment that does
   * nothing (e.g., empty comment with a status change that has already been
   * performed by another user).
   *
   * @param bool  True to drop transactions without effect and continue.
   * @return this
   */
  public function setContinueOnNoEffect($continue) {
    $this->continueOnNoEffect = $continue;
    return $this;
  }

  public function getContinueOnNoEffect() {
    return $this->continueOnNoEffect;
  }

  /**
   * Not strictly necessary, but reply handlers ideally set this value to
   * make email threading work better.
   */
  public function setParentMessageID($parent_message_id) {
    $this->parentMessageID = $parent_message_id;
    return $this;
  }
  public function getParentMessageID() {
    return $this->parentMessageID;
  }

  protected function getIsNewObject() {
    return $this->isNewObject;
  }

  protected function getMentionedPHIDs() {
    return $this->mentionedPHIDs;
  }

  public function setIsPreview($is_preview) {
    $this->isPreview = $is_preview;
    return $this;
  }

  public function getIsPreview() {
    return $this->isPreview;
  }

  public function getTransactionTypes() {
    $types = array();

    if ($this->object instanceof PhabricatorSubscribableInterface) {
      $types[] = PhabricatorTransactions::TYPE_SUBSCRIBERS;
    }

    return $types;
  }

  private function adjustTransactionValues(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {
    $old = $this->getTransactionOldValue($object, $xaction);
    $xaction->setOldValue($old);

    $new = $this->getTransactionNewValue($object, $xaction);
    $xaction->setNewValue($new);
  }

  private function getTransactionOldValue(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {
    switch ($xaction->getTransactionType()) {
      case PhabricatorTransactions::TYPE_SUBSCRIBERS:
        if ($object->getPHID()) {
          $old_phids = PhabricatorSubscribersQuery::loadSubscribersForPHID(
            $object->getPHID());
        } else {
          $old_phids = array();
        }
        return array_values($old_phids);
      case PhabricatorTransactions::TYPE_VIEW_POLICY:
        return $object->getViewPolicy();
      case PhabricatorTransactions::TYPE_EDIT_POLICY:
        return $object->getEditPolicy();
      default:
        return $this->getCustomTransactionOldValue($object, $xaction);
    }
  }

  private function getTransactionNewValue(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {
    switch ($xaction->getTransactionType()) {
      case PhabricatorTransactions::TYPE_SUBSCRIBERS:
        return $this->getPHIDTransactionNewValue($xaction);
      case PhabricatorTransactions::TYPE_VIEW_POLICY:
      case PhabricatorTransactions::TYPE_EDIT_POLICY:
        return $xaction->getNewValue();
      default:
        return $this->getCustomTransactionNewValue($object, $xaction);
    }
  }

  protected function getCustomTransactionOldValue(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {
    throw new Exception("Capability not supported!");
  }

  protected function getCustomTransactionNewValue(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {
    throw new Exception("Capability not supported!");
  }

  protected function transactionHasEffect(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {

    switch ($xaction->getTransactionType()) {
      case PhabricatorTransactions::TYPE_COMMENT:
        return $xaction->hasComment();
    }

    return ($xaction->getOldValue() !== $xaction->getNewValue());
  }

  private function applyInternalEffects(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {
    switch ($xaction->getTransactionType()) {
      case PhabricatorTransactions::TYPE_COMMENT:
        break;
      case PhabricatorTransactions::TYPE_VIEW_POLICY:
        $object->setViewPolicy($xaction->getNewValue());
        break;
      case PhabricatorTransactions::TYPE_EDIT_POLICY:
        $object->setEditPolicy($xaction->getNewValue());
        break;
      default:
        return $this->applyCustomInternalTransaction($object, $xaction);
    }
  }

  private function applyExternalEffects(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {
    switch ($xaction->getTransactionType()) {
      case PhabricatorTransactions::TYPE_COMMENT:
        break;
      case PhabricatorTransactions::TYPE_SUBSCRIBERS:
        $subeditor = id(new PhabricatorSubscriptionsEditor())
          ->setObject($object)
          ->setActor($this->requireActor())
          ->subscribeExplicit($xaction->getNewValue())
          ->save();
        break;
      default:
        return $this->applyCustomExternalTransaction($object, $xaction);
    }
  }

  protected function applyCustomInternalTransaction(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {
    throw new Exception("Capability not supported!");
  }

  protected function applyCustomExternalTransaction(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {
    throw new Exception("Capability not supported!");
  }

  public function setContentSource(PhabricatorContentSource $content_source) {
    $this->contentSource = $content_source;
    return $this;
  }

  public function getContentSource() {
    return $this->contentSource;
  }

  final public function applyTransactions(
    PhabricatorLiskDAO $object,
    array $xactions) {

    $this->object = $object;
    $this->xactions = $xactions;
    $this->isNewObject = ($object->getPHID() === null);

    $this->validateEditParameters($object, $xactions);


    $actor = $this->requireActor();

    $mention_xaction = $this->buildMentionTransaction($object, $xactions);
    if ($mention_xaction) {
      $xactions[] = $mention_xaction;
    }

    $xactions = $this->combineTransactions($xactions);

    foreach ($xactions as $xaction) {
      // TODO: This needs to be more sophisticated once we have meta-policies.
      $xaction->setViewPolicy(PhabricatorPolicies::POLICY_PUBLIC);
      $xaction->setEditPolicy($actor->getPHID());

      $xaction->setAuthorPHID($actor->getPHID());
      $xaction->setContentSource($this->getContentSource());
    }

    foreach ($xactions as $xaction) {
      $this->adjustTransactionValues($object, $xaction);
    }

    $xactions = $this->filterTransactions($object, $xactions);

    if (!$xactions) {
      return array();
    }

    $xactions = $this->sortTransactions($xactions);

    if ($this->getIsPreview()) {
      $this->loadHandles($xactions);
      return $xactions;
    }

    $comment_editor = id(new PhabricatorApplicationTransactionCommentEditor())
      ->setActor($actor)
      ->setContentSource($this->getContentSource());

    $object->openTransaction();
      foreach ($xactions as $xaction) {
        $this->applyInternalEffects($object, $xaction);
      }

      $object->save();

      foreach ($xactions as $xaction) {
        $xaction->setObjectPHID($object->getPHID());
        if ($xaction->getComment()) {
          $xaction->setPHID($xaction->generatePHID());
          $comment_editor->applyEdit($xaction, $xaction->getComment());
        } else {
          $xaction->save();
        }
      }

      foreach ($xactions as $xaction) {
        $this->applyExternalEffects($object, $xaction);
      }
    $object->saveTransaction();

    $this->loadHandles($xactions);

    $mail = null;
    if ($this->supportsMail()) {
      $mail = $this->sendMail($object, $xactions);
    }

    if ($this->supportsSearch()) {
      id(new PhabricatorSearchIndexer())
        ->indexDocumentByPHID($object->getPHID());
    }

    if ($this->supportsFeed()) {
      $mailed = array();
      if ($mail) {
        $mailed = $mail->buildRecipientList();
      }
      $this->publishFeedStory(
        $object,
        $xactions,
        $mailed);
    }

    $this->didApplyTransactions($xactions);

    return $xactions;
  }

  protected function didApplyTransactions(array $xactions) {
    // Hook for subclasses.
    return;
  }

  private function loadHandles(array $xactions) {
    $phids = array();
    foreach ($xactions as $key => $xaction) {
      $phids[$key] = $xaction->getRequiredHandlePHIDs();
    }
    $handles = array();
    $merged = array_mergev($phids);
    if ($merged) {
      $handles = id(new PhabricatorObjectHandleData($merged))
        ->setViewer($this->requireActor())
        ->loadHandles();
    }
    foreach ($xactions as $key => $xaction) {
      $xaction->setHandles(array_select_keys($handles, $phids[$key]));
    }
  }

  private function validateEditParameters(
    PhabricatorLiskDAO $object,
    array $xactions) {

    if (!$this->getContentSource()) {
      throw new Exception(
        "Call setContentSource() before applyTransactions()!");
    }

    // Do a bunch of sanity checks that the incoming transactions are fresh.
    // They should be unsaved and have only "transactionType" and "newValue"
    // set.

    $types = array_fill_keys($this->getTransactionTypes(), true);

    assert_instances_of($xactions, 'PhabricatorApplicationTransaction');
    foreach ($xactions as $xaction) {
      if ($xaction->getPHID() || $xaction->getID()) {
        throw new Exception(
          "You can not apply transactions which already have IDs/PHIDs!");
      }
      if ($xaction->getObjectPHID()) {
        throw new Exception(
          "You can not apply transactions which already have objectPHIDs!");
      }
      if ($xaction->getAuthorPHID()) {
        throw new Exception(
          "You can not apply transactions which already have authorPHIDs!");
      }
      if ($xaction->getCommentPHID()) {
        throw new Exception(
          "You can not apply transactions which already have commentPHIDs!");
      }
      if ($xaction->getCommentVersion() !== 0) {
        throw new Exception(
          "You can not apply transactions which already have commentVersions!");
      }
      if ($xaction->getOldValue() !== null) {
        throw new Exception(
          "You can not apply transactions which already have oldValue!");
      }

      $type = $xaction->getTransactionType();
      if (empty($types[$type])) {
        throw new Exception("Transaction has unknown type '{$type}'.");
      }
    }

    // The actor must have permission to view and edit the object.

    $actor = $this->requireActor();

    PhabricatorPolicyFilter::requireCapability(
      $actor,
      $xaction,
      PhabricatorPolicyCapability::CAN_VIEW);

    PhabricatorPolicyFilter::requireCapability(
      $actor,
      $xaction,
      PhabricatorPolicyCapability::CAN_EDIT);
  }

  private function buildMentionTransaction(
    PhabricatorLiskDAO $object,
    array $xactions) {

    if (!($object instanceof PhabricatorSubscribableInterface)) {
      return null;
    }

    $texts = array();
    foreach ($xactions as $xaction) {
      $texts[] = $this->getMentionableTextsFromTransaction($xaction);
    }
    $texts = array_mergev($texts);

    $phids = PhabricatorMarkupEngine::extractPHIDsFromMentions($texts);

    $this->mentionedPHIDs = $phids;

    if (!$phids) {
      return null;
    }

    if ($object->getPHID()) {
      // Don't try to subscribe already-subscribed mentions: we want to generate
      // a dialog about an action having no effect if the user explicitly adds
      // existing CCs, but not if they merely mention existing subscribers.
      $current = PhabricatorSubscribersQuery::loadSubscribersForPHID(
        $object->getPHID());
      $phids = array_diff($phids, $current);
    }

    if (!$phids) {
      return null;
    }

    $xaction = newv(get_class(head($xactions)), array());
    $xaction->setTransactionType(PhabricatorTransactions::TYPE_SUBSCRIBERS);
    $xaction->setNewValue(array('+' => $phids));

    return $xaction;
  }

  protected function getMentionableTextsFromTransaction(
    PhabricatorApplicationTransaction $transaction) {
    $texts = array();
    if ($transaction->getComment()) {
      $texts[] = $transaction->getComment()->getContent();
    }
    return $texts;
  }

  protected function mergeTransactions(
    PhabricatorApplicationTransaction $u,
    PhabricatorApplicationTransaction $v) {

    $type = $u->getTransactionType();

    switch ($type) {
      case PhabricatorTransactions::TYPE_SUBSCRIBERS:
        return $this->mergePHIDTransactions($u, $v);
    }

    // By default, do not merge the transactions.
    return null;
  }


  /**
   * Attempt to combine similar transactions into a smaller number of total
   * transactions. For example, two transactions which edit the title of an
   * object can be merged into a single edit.
   */
  private function combineTransactions(array $xactions) {
    $stray_comments = array();

    $result = array();
    $types = array();
    foreach ($xactions as $key => $xaction) {
      $type = $xaction->getTransactionType();
      if (isset($types[$type])) {
        foreach ($types[$type] as $other_key) {
          $merged = $this->mergeTransactions($result[$other_key], $xaction);
          if ($merged) {
            $result[$other_key] = $merged;

            if ($xaction->getComment() &&
                ($xaction->getComment() !== $merged->getComment())) {
              $stray_comments[] = $xaction->getComment();
            }

            if ($result[$other_key]->getComment() &&
                ($result[$other_key]->getComment() !== $merged->getComment())) {
              $stray_comments[] = $result[$other_key]->getComment();
            }

            // Move on to the next transaction.
            continue 2;
          }
        }
      }
      $result[$key] = $xaction;
      $types[$type][] = $key;
    }

    // If we merged any comments away, restore them.
    foreach ($stray_comments as $comment) {
      $xaction = newv(get_class(head($result)), array());
      $xaction->setTransactionType(PhabricatorTransactions::TYPE_COMMENT);
      $xaction->setComment($comment);
      $result[] = $xaction;
    }

    return array_values($result);
  }

  protected function mergePHIDTransactions(
    PhabricatorApplicationTransaction $u,
    PhabricatorApplicationTransaction $v) {

    $result = $u->getNewValue();
    foreach ($v->getNewValue() as $key => $value) {
      $result[$key] = array_merge($value, idx($result, $key, array()));
    }
    $u->setNewValue($result);

    return $u;
  }


  protected function getPHIDTransactionNewValue(
    PhabricatorApplicationTransaction $xaction) {

    $old = array_fuse($xaction->getOldValue());

    $new = $xaction->getNewValue();
    $new_add = idx($new, '+', array());
    unset($new['+']);
    $new_rem = idx($new, '-', array());
    unset($new['-']);
    $new_set = idx($new, '=', null);
    if ($new_set !== null) {
      $new_set = array_fuse($new_set);
    }
    unset($new['=']);

    if ($new) {
      throw new Exception(
        "Invalid 'new' value for PHID transaction. Value should contain only ".
        "keys '+' (add PHIDs), '-' (remove PHIDs) and '=' (set PHIDS).");
    }

    $result = array();

    foreach ($old as $phid) {
      if ($new_set !== null && empty($new_set[$phid])) {
        continue;
      }
      $result[$phid] = $phid;
    }

    if ($new_set !== null) {
      foreach ($new_set as $phid) {
        $result[$phid] = $phid;
      }
    }

    foreach ($new_add as $phid) {
      $result[$phid] = $phid;
    }

    foreach ($new_rem as $phid) {
      unset($result[$phid]);
    }

    return array_values($result);
  }

  protected function sortTransactions(array $xactions) {
    $head = array();
    $tail = array();

    // Move bare comments to the end, so the actions preceed them.
    foreach ($xactions as $xaction) {
      $type = $xaction->getTransactionType();
      if ($type == PhabricatorTransactions::TYPE_COMMENT) {
        $tail[] = $xaction;
      } else {
        $head[] = $xaction;
      }
    }

    return array_values(array_merge($head, $tail));
  }


  protected function filterTransactions(
    PhabricatorLiskDAO $object,
    array $xactions) {

    $type_comment = PhabricatorTransactions::TYPE_COMMENT;

    $no_effect = array();
    $has_comment = false;
    $any_effect = false;
    foreach ($xactions as $key => $xaction) {
      if ($this->transactionHasEffect($object, $xaction)) {
        if ($xaction->getTransactionType() != $type_comment) {
          $any_effect = true;
        }
      } else {
        $no_effect[$key] = $xaction;
      }
      if ($xaction->hasComment()) {
        $has_comment = true;
      }
    }

    if (!$no_effect) {
      return $xactions;
    }

    if (!$this->getContinueOnNoEffect() && !$this->getIsPreview()) {
      throw new PhabricatorApplicationTransactionNoEffectException(
        $no_effect,
        $any_effect,
        $has_comment);
    }

    if (!$any_effect && !$has_comment) {
      // If we only have empty comment transactions, just drop them all.
      return array();
    }

    foreach ($no_effect as $key => $xaction) {
      if ($xaction->getComment()) {
        $xaction->setTransactionType($type_comment);
        $xaction->setOldValue(null);
        $xaction->setNewValue(null);
      } else {
        unset($xactions[$key]);
      }
    }

    return $xactions;
  }



/* -(  Sending Mail  )------------------------------------------------------- */


  /**
   * @task mail
   */
  protected function supportsMail() {
    return false;
  }


  /**
   * @task mail
   */
  protected function sendMail(
    PhabricatorLiskDAO $object,
    array $xactions) {

    $email_to = $this->getMailTo($object);
    $email_cc = $this->getMailCC($object);

    $phids = array_merge($email_to, $email_cc);
    $handles = id(new PhabricatorObjectHandleData($phids))
      ->setViewer($this->requireActor())
      ->loadHandles();

    $template = $this->buildMailTemplate($object);
    $body = $this->buildMailBody($object, $xactions);

    $mail_tags = $this->getMailTags($object, $xactions);

    $action = $this->getStrongestAction($object, $xactions)->getActionName();

    $template
      ->setFrom($this->requireActor()->getPHID())
      ->setSubjectPrefix($this->getMailSubjectPrefix())
      ->setVarySubjectPrefix('['.$action.']')
      ->setThreadID($object->getPHID(), $this->getIsNewObject())
      ->setRelatedPHID($object->getPHID())
      ->setExcludeMailRecipientPHIDs($this->getExcludeMailRecipientPHIDs())
      ->setMailTags($mail_tags)
      ->setIsBulk(true)
      ->setBody($body->render());

    if ($this->getParentMessageID()) {
      $template->setParentMessageID($this->getParentMessageID());
    }

    $mails = $this
      ->buildReplyHandler($object)
      ->multiplexMail(
        $template,
        array_select_keys($handles, $email_to),
        array_select_keys($handles, $email_cc));

    foreach ($mails as $mail) {
      $mail->saveAndSend();
    }

    $template->addTos($email_to);
    $template->addCCs($email_cc);

    return $template;
  }


  /**
   * @task mail
   */
  protected function getStrongestAction(
    PhabricatorLiskDAO $object,
    array $xactions) {
    return last(msort($xactions, 'getActionStrength'));
  }


  /**
   * @task mail
   */
  protected function buildReplyHandler(PhabricatorLiskDAO $object) {
    throw new Exception("Capability not supported.");
  }


  /**
   * @task mail
   */
  protected function getMailSubjectPrefix() {
    throw new Exception("Capability not supported.");
  }


  /**
   * @task mail
   */
  protected function getMailTags(
    PhabricatorLiskDAO $object,
    array $xactions) {
    $tags = array();

    foreach ($xactions as $xaction) {
      $tags[] = $xaction->getMailTags();
    }

    return array_mergev($tags);
  }


  /**
   * @task mail
   */
  protected function buildMailTemplate(PhabricatorLiskDAO $object) {
    throw new Exception("Capability not supported.");
  }


  /**
   * @task mail
   */
  protected function getMailTo(PhabricatorLiskDAO $object) {
    throw new Exception("Capability not supported.");
  }


  /**
   * @task mail
   */
  protected function getMailCC(PhabricatorLiskDAO $object) {
    if ($object instanceof PhabricatorSubscribableInterface) {
      $phid = $object->getPHID();
      return PhabricatorSubscribersQuery::loadSubscribersForPHID($phid);
    }
    throw new Exception("Capability not supported.");
  }


  /**
   * @task mail
   */
  protected function buildMailBody(
    PhabricatorLiskDAO $object,
    array $xactions) {

    $headers = array();
    $comments = array();

    foreach ($xactions as $xaction) {
      $headers[] = id(clone $xaction)->setRenderingTarget('text')->getTitle();
      $comment = $xaction->getComment();
      if ($comment && strlen($comment->getContent())) {
        $comments[] = $comment->getContent();
      }
    }

    $body = new PhabricatorMetaMTAMailBody();
    $body->addRawSection(implode("\n", $headers));

    foreach ($comments as $comment) {
      $body->addRawSection($comment);
    }

    return $body;
  }


/* -(  Publishing Feed Stories  )-------------------------------------------- */


  /**
   * @task feed
   */
  protected function supportsFeed() {
    return false;
  }


  /**
   * @task feed
   */
  protected function getFeedStoryType() {
    return 'PhabricatorApplicationTransactionFeedStory';
  }


  /**
   * @task feed
   */
  protected function getFeedRelatedPHIDs(
    PhabricatorLiskDAO $object,
    array $xactions) {

    return array(
      $object->getPHID(),
      $this->requireActor()->getPHID(),
    );
  }


  /**
   * @task feed
   */
  protected function getFeedNotifyPHIDs(
    PhabricatorLiskDAO $object,
    array $xactions) {

    return array_merge(
      $this->getMailTo($object),
      $this->getMailCC($object));
  }


  /**
   * @task feed
   */
  protected function getFeedStoryData(
    PhabricatorLiskDAO $object,
    array $xactions) {

    $xactions = msort($xactions, 'getActionStrength');
    $xactions = array_reverse($xactions);

    return array(
      'objectPHID'        => $object->getPHID(),
      'transactionPHIDs'  => mpull($xactions, 'getPHID'),
    );
  }


  /**
   * @task feed
   */
  protected function publishFeedStory(
    PhabricatorLiskDAO $object,
    array $xactions,
    array $mailed_phids) {

    $related_phids = $this->getFeedRelatedPHIDs($object, $xactions);
    $subscribed_phids = $this->getFeedNotifyPHIDs($object, $xactions);

    $story_type = $this->getFeedStoryType();
    $story_data = $this->getFeedStoryData($object, $xactions);

    id(new PhabricatorFeedStoryPublisher())
      ->setStoryType($story_type)
      ->setStoryData($story_data)
      ->setStoryTime(time())
      ->setStoryAuthorPHID($this->requireActor()->getPHID())
      ->setRelatedPHIDs($related_phids)
      ->setPrimaryObjectPHID($object->getPHID())
      ->setSubscribedPHIDs($subscribed_phids)
      ->setMailRecipientPHIDs($mailed_phids)
      ->publish();
  }


/* -(  Search Index  )------------------------------------------------------- */


  /**
   * @task search
   */
  protected function supportsSearch() {
    return false;
  }

}
