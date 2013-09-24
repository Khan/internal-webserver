<?php

final class PonderQuestion extends PonderDAO
  implements
    PhabricatorMarkupInterface,
    PonderVotableInterface,
    PhabricatorSubscribableInterface,
    PhabricatorPolicyInterface,
    PhabricatorTokenReceiverInterface {

  const MARKUP_FIELD_CONTENT = 'markup:content';

  protected $title;
  protected $phid;

  protected $authorPHID;
  protected $status;
  protected $content;
  protected $contentSource;

  protected $voteCount;
  protected $answerCount;
  protected $heat;
  protected $mailKey;

  private $answers;
  private $vote;
  private $comments;

  public function getConfiguration() {
    return array(
      self::CONFIG_AUX_PHID => true,
    ) + parent::getConfiguration();
  }

  public function generatePHID() {
    return PhabricatorPHID::generateNewPHID(PonderPHIDTypeQuestion::TYPECONST);
  }

  public function setContentSource(PhabricatorContentSource $content_source) {
    $this->contentSource = $content_source->serialize();
    return $this;
  }

  public function getContentSource() {
    return PhabricatorContentSource::newFromSerialized($this->contentSource);
  }

  public function attachRelated() {
    $this->answers = $this->loadRelatives(new PonderAnswer(), "questionID");
    $qa_phids = mpull($this->answers, 'getPHID') + array($this->getPHID());

    if ($qa_phids) {
      $comments = id(new PonderCommentQuery())
        ->withTargetPHIDs($qa_phids)
        ->execute();

      $comments = mgroup($comments, 'getTargetPHID');
    } else {
      $comments = array();
    }

    $this->setComments(idx($comments, $this->getPHID(), array()));
    foreach ($this->answers as $answer) {
      $answer->attachQuestion($this);
      $answer->setComments(idx($comments, $answer->getPHID(), array()));
    }
  }

  public function attachVotes($user_phid) {
    $qa_phids = mpull($this->answers, 'getPHID') + array($this->getPHID());

    $edges = id(new PhabricatorEdgeQuery())
      ->withSourcePHIDs(array($user_phid))
      ->withDestinationPHIDs($qa_phids)
      ->withEdgeTypes(
        array(
          PhabricatorEdgeConfig::TYPE_VOTING_USER_HAS_QUESTION,
          PhabricatorEdgeConfig::TYPE_VOTING_USER_HAS_ANSWER
        ))
      ->needEdgeData(true)
      ->execute();

    $question_edge =
      $edges[$user_phid][PhabricatorEdgeConfig::TYPE_VOTING_USER_HAS_QUESTION];
    $answer_edges =
      $edges[$user_phid][PhabricatorEdgeConfig::TYPE_VOTING_USER_HAS_ANSWER];
    $edges = null;

    $this->setUserVote(idx($question_edge, $this->getPHID()));
    foreach ($this->answers as $answer) {
      $answer->setUserVote(idx($answer_edges, $answer->getPHID()));
    }
  }

  public function setUserVote($vote) {
    $this->vote = $vote['data'];
    if (!$this->vote) {
      $this->vote = PonderVote::VOTE_NONE;
    }
    return $this;
  }

  public function attachUserVote($user_phid, $vote) {
    $this->vote = $vote;
    return $this;
  }

  public function getUserVote() {
    return $this->vote;
  }

  public function setComments($comments) {
    $this->comments = $comments;
    return $this;
  }

  public function getComments() {
    return $this->comments;
  }

  public function attachAnswers(array $answers) {
    assert_instances_of($answers, 'PonderAnswer');
    $this->answers = $answers;
    return $this;
  }

  public function getAnswers() {
    return $this->answers;
  }

  public function getMarkupField() {
    return self::MARKUP_FIELD_CONTENT;
  }

  // Markup interface

  public function getMarkupFieldKey($field) {
    $hash = PhabricatorHash::digest($this->getMarkupText($field));
    $id = $this->getID();
    return "ponder:Q{$id}:{$field}:{$hash}";
  }

  public function getMarkupText($field) {
    return $this->getContent();
  }

  public function newMarkupEngine($field) {
    return PhabricatorMarkupEngine::getEngine();
  }

  public function didMarkupText(
    $field,
    $output,
    PhutilMarkupEngine $engine) {
    return $output;
  }

  public function shouldUseMarkupCache($field) {
    return (bool)$this->getID();
  }

  // votable interface
  public function getUserVoteEdgeType() {
    return PhabricatorEdgeConfig::TYPE_VOTING_USER_HAS_QUESTION;
  }

  public function getVotablePHID() {
    return $this->getPHID();
  }

  public function isAutomaticallySubscribed($phid) {
    return ($phid == $this->getAuthorPHID());
  }

  public function save() {
    if (!$this->getMailKey()) {
      $this->setMailKey(Filesystem::readRandomCharacters(20));
    }
    return parent::save();
  }

  public function getCapabilities() {
    return array(
      PhabricatorPolicyCapability::CAN_VIEW,
      PhabricatorPolicyCapability::CAN_EDIT,
    );
  }

  public function getPolicy($capability) {
    $policy = PhabricatorPolicies::POLICY_NOONE;

    switch ($capability) {
      case PhabricatorPolicyCapability::CAN_VIEW:
        $policy = PhabricatorPolicies::POLICY_USER;
        break;
    }

    return $policy;
  }

  public function hasAutomaticCapability($capability, PhabricatorUser $viewer) {
    return ($viewer->getPHID() == $this->getAuthorPHID());
  }

  public function getOriginalTitle() {
    // TODO: Make this actually save/return the original title.
    return $this->getTitle();
  }

  public function getFullTitle() {
    $id = $this->getID();
    $title = $this->getTitle();
    return "Q{$id}: {$title}";
  }


/* -(  PhabricatorTokenReceiverInterface  )---------------------------------- */


  public function getUsersToNotifyOfTokenGiven() {
    return array(
      $this->getAuthorPHID(),
    );
  }

}
