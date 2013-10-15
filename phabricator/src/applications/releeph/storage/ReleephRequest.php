<?php

final class ReleephRequest extends ReleephDAO
  implements
    PhabricatorPolicyInterface,
    PhabricatorCustomFieldInterface {

  protected $branchID;
  protected $requestUserPHID;
  protected $details = array();
  protected $userIntents = array();
  protected $inBranch;
  protected $pickStatus;
  protected $mailKey;

  // Information about the thing being requested
  protected $requestCommitPHID;

  // Information about the last commit to the releeph branch
  protected $commitIdentifier;
  protected $commitPHID;

  // Pre-populated handles that we'll bulk load in ReleephBranch
  private $handles = self::ATTACHABLE;
  private $customFields = self::ATTACHABLE;



/* -(  Constants and helper methods  )--------------------------------------- */

  const INTENT_WANT = 'want';
  const INTENT_PASS = 'pass';

  const PICK_PENDING  = 1; // old
  const PICK_FAILED   = 2;
  const PICK_OK       = 3;
  const PICK_MANUAL   = 4; // old
  const REVERT_OK     = 5;
  const REVERT_FAILED = 6;

  public function shouldBeInBranch() {
    return
      $this->getPusherIntent() == self::INTENT_WANT &&
      /**
       * We use "!= pass" instead of "== want" in case the requestor intent is
       * not present.  In other words, only revert if the requestor explicitly
       * passed.
       */
      $this->getRequestorIntent() != self::INTENT_PASS;
  }

  /**
   * Will return INTENT_WANT if any pusher wants this request, and no pusher
   * passes on this request.
   */
  public function getPusherIntent() {
    $project = $this->loadReleephProject();
    if (!$project) {
      return null;
    }

    if (!$project->getPushers()) {
      return self::INTENT_WANT;
    }

    $found_pusher_want = false;
    foreach ($this->userIntents as $phid => $intent) {
      if ($project->isAuthoritativePHID($phid)) {
        if ($intent == self::INTENT_PASS) {
          return self::INTENT_PASS;
        }

        $found_pusher_want = true;
      }
    }

    if ($found_pusher_want) {
      return self::INTENT_WANT;
    } else {
      return null;
    }
  }

  public function getRequestorIntent() {
    return idx($this->userIntents, $this->requestUserPHID);
  }

  public function getStatus() {
    return $this->calculateStatus();
  }

  private function calculateStatus() {
    if ($this->shouldBeInBranch()) {
      if ($this->getInBranch()) {
        return ReleephRequestStatus::STATUS_PICKED;
      } else {
        return ReleephRequestStatus::STATUS_NEEDS_PICK;
      }
    } else {
      if ($this->getInBranch()) {
        return ReleephRequestStatus::STATUS_NEEDS_REVERT;
      } else {
        $has_been_in_branch = $this->getCommitIdentifier();
        // Regardless of why we reverted something, always say reverted if it
        // was once in the branch.
        if ($has_been_in_branch) {
          return ReleephRequestStatus::STATUS_REVERTED;
        } elseif ($this->getPusherIntent() === ReleephRequest::INTENT_PASS) {
          // Otherwise, if it has never been in the branch, explicitly say why:
          return ReleephRequestStatus::STATUS_REJECTED;
        } elseif ($this->getRequestorIntent() === ReleephRequest::INTENT_WANT) {
          return ReleephRequestStatus::STATUS_REQUESTED;
        } else {
          return ReleephRequestStatus::STATUS_ABANDONED;
        }
      }
    }
  }


/* -(  Lisk mechanics  )----------------------------------------------------- */

  public function getConfiguration() {
    return array(
      self::CONFIG_AUX_PHID => true,
      self::CONFIG_SERIALIZATION => array(
        'details' => self::SERIALIZATION_JSON,
        'userIntents' => self::SERIALIZATION_JSON,
      ),
    ) + parent::getConfiguration();
  }

  public function generatePHID() {
    return PhabricatorPHID::generateNewPHID(
      ReleephPHIDTypeRequest::TYPECONST);
  }

  public function save() {
    if (!$this->getMailKey()) {
      $this->setMailKey(Filesystem::readRandomCharacters(20));
    }
    return parent::save();
  }


/* -(  Helpful accessors )--------------------------------------------------- */

  public function setHandles($handles) {
    $this->handles = $handles;
    return $this;
  }

  public function getHandles() {
    return $this->assertAttached($this->handles);
  }

  public function getDetail($key, $default = null) {
    return idx($this->getDetails(), $key, $default);
  }

  public function setDetail($key, $value) {
    $this->details[$key] = $value;
    return $this;
  }

  public function getReason() {
    // Backward compatibility: reason used to be called comments
    $reason = $this->getDetail('reason');
    if (!$reason) {
      return $this->getDetail('comments');
    }
    return $reason;
  }

  public function getSummary() {
    /**
     * Instead, you can use:
     *  - getDetail('summary')    // the actual user-chosen summary
     *  - getSummaryForDisplay()  // falls back to the original commit title
     *
     * Or for the fastidious:
     *  - id(new ReleephSummaryFieldSpecification())
     *      ->setReleephRequest($rr)
     *      ->getValue()          // programmatic equivalent to getDetail()
     */
    throw new Exception(
      "getSummary() has been deprecated!");
  }

  /**
   * Allow a null summary, and fall back to the title of the commit.
   */
  public function getSummaryForDisplay() {
    $summary = $this->getDetail('summary');

    if (!$summary) {
      $pr_commit_data = $this->loadPhabricatorRepositoryCommitData();
      if ($pr_commit_data) {
        $message_lines = explode("\n", $pr_commit_data->getCommitMessage());
        $message_lines = array_filter($message_lines);
        $summary = head($message_lines);
      }
    }

    if (!$summary) {
      $summary = '(no summary given and commit message empty or unparsed)';
    }

    return $summary;
  }

  public function loadRequestCommitDiffPHID() {
    $phids = array();
    $commit = $this->loadPhabricatorRepositoryCommit();
    if ($commit) {
      $phids = PhabricatorEdgeQuery::loadDestinationPHIDs(
        $commit->getPHID(),
        PhabricatorEdgeConfig::TYPE_COMMIT_HAS_DREV);
    }

    return head($phids);
  }


/* -(  Loading external objects  )------------------------------------------- */

  public function loadReleephBranch() {
    return $this->loadOneRelative(
      new ReleephBranch(),
      'id',
      'getBranchID');
  }

  public function loadReleephProject() {
    $branch = $this->loadReleephBranch();
    if ($branch) {
      return $branch->loadReleephProject();
    }
  }

  public function loadPhabricatorRepositoryCommit() {
    return $this->loadOneRelative(
      new PhabricatorRepositoryCommit(),
      'phid',
      'getRequestCommitPHID');
  }

  public function loadPhabricatorRepositoryCommitData() {
    $commit = $this->loadPhabricatorRepositoryCommit();
    if ($commit) {
      return $commit->loadOneRelative(
        new PhabricatorRepositoryCommitData(),
        'commitID');
    }
  }

  // TODO: (T603) Get rid of all this one-off ad-hoc loading.
  public function loadDifferentialRevision() {
    $diff_phid = $this->loadRequestCommitDiffPHID();
    if (!$diff_phid) {
      return null;
    }
    return $this->loadOneRelative(
        new DifferentialRevision(),
        'phid',
        'loadRequestCommitDiffPHID');
  }


/* -(  State change helpers  )----------------------------------------------- */

  public function setUserIntent(PhabricatorUser $user, $intent) {
    $this->userIntents[$user->getPHID()] = $intent;
    return $this;
  }


/* -(  Migrating to status-less ReleephRequests  )--------------------------- */

  protected function didReadData() {
    if ($this->userIntents === null) {
      $this->userIntents = array();
    }
  }

  public function setStatus($value) {
    throw new Exception('`status` is now deprecated!');
  }

/* -(  Make magic Lisk methods private  )------------------------------------ */

  private function setUserIntents(array $ar) {
    return parent::setUserIntents($ar);
  }


/* -(  PhabricatorPolicyInterface  )----------------------------------------- */


  public function getCapabilities() {
    return array(
      PhabricatorPolicyCapability::CAN_VIEW,
      PhabricatorPolicyCapability::CAN_EDIT,
    );
  }

  public function getPolicy($capability) {
    return PhabricatorPolicies::POLICY_USER;
  }

  public function hasAutomaticCapability($capability, PhabricatorUser $viewer) {
    return false;
  }

  public function describeAutomaticCapability($capability) {
    return null;
  }



/* -(  PhabricatorCustomFieldInterface  )------------------------------------ */


  public function getCustomFieldSpecificationForRole($role) {
    return PhabricatorEnv::getEnvConfig('releeph.fields');
  }

  public function getCustomFieldBaseClass() {
    return 'ReleephFieldSpecification';
  }

  public function getCustomFields() {
    return $this->assertAttached($this->customFields);
  }

  public function attachCustomFields(PhabricatorCustomFieldAttachment $fields) {
    $this->customFields = $fields;
    return $this;
  }


}
