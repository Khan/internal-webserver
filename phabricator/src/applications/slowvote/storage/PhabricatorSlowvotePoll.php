<?php

/**
 * @group slowvote
 */
final class PhabricatorSlowvotePoll extends PhabricatorSlowvoteDAO
  implements
    PhabricatorPolicyInterface,
    PhabricatorSubscribableInterface,
    PhabricatorTokenReceiverInterface {

  const RESPONSES_VISIBLE = 0;
  const RESPONSES_VOTERS  = 1;
  const RESPONSES_OWNER   = 2;

  const METHOD_PLURALITY  = 0;
  const METHOD_APPROVAL   = 1;

  protected $question;
  protected $description;
  protected $authorPHID;
  protected $responseVisibility;
  protected $shuffle;
  protected $method;
  protected $viewPolicy;

  private $options;
  private $choices;
  private $viewerChoices = array();

  public function getConfiguration() {
    return array(
      self::CONFIG_AUX_PHID => true,
    ) + parent::getConfiguration();
  }

  public function generatePHID() {
    return PhabricatorPHID::generateNewPHID(
      PhabricatorSlowvotePHIDTypePoll::TYPECONST);
  }

  public function getOptions() {
    if ($this->options === null) {
      throw new Exception("Call attachOptions() before getOptions()!");
    }
    return $this->options;
  }

  public function attachOptions(array $options) {
    assert_instances_of($options, 'PhabricatorSlowvoteOption');
    $this->options = $options;
    return $this;
  }

  public function getChoices() {
    if ($this->choices === null) {
      throw new Exception("Call attachChoices() before getChoices()!");
    }
    return $this->choices;
  }

  public function attachChoices(array $choices) {
    assert_instances_of($choices, 'PhabricatorSlowvoteChoice');
    $this->choices = $choices;
    return $this;
  }

  public function getViewerChoices(PhabricatorUser $viewer) {
    if (idx($this->viewerChoices, $viewer->getPHID()) === null) {
      throw new Exception(
        "Call attachViewerChoices() before getViewerChoices()!");
    }
    return idx($this->viewerChoices, $viewer->getPHID());
  }

  public function attachViewerChoices(PhabricatorUser $viewer, array $choices) {
    assert_instances_of($choices, 'PhabricatorSlowvoteChoice');
    $this->viewerChoices[$viewer->getPHID()] = $choices;
    return $this;
  }


/* -(  PhabricatorPolicyInterface  )----------------------------------------- */


  public function getCapabilities() {
    return array(
      PhabricatorPolicyCapability::CAN_VIEW,
      PhabricatorPolicyCapability::CAN_EDIT,
    );
  }

  public function getPolicy($capability) {
    switch ($capability) {
      case PhabricatorPolicyCapability::CAN_VIEW:
        return $this->viewPolicy;
      case PhabricatorPolicyCapability::CAN_EDIT:
        return PhabricatorPolicies::POLICY_NOONE;
    }
  }

  public function hasAutomaticCapability($capability, PhabricatorUser $viewer) {
    return ($viewer->getPHID() == $this->getAuthorPHID());
  }


/* -(  PhabricatorSubscribableInterface  )----------------------------------- */


  public function isAutomaticallySubscribed($phid) {
    return ($phid == $this->getAuthorPHID());
  }


/* -(  PhabricatorTokenReceiverInterface  )---------------------------------- */


  public function getUsersToNotifyOfTokenGiven() {
    return array($this->getAuthorPHID());
  }


}
