<?php

final class ReleephBranch extends ReleephDAO
  implements PhabricatorPolicyInterface {

  protected $phid;
  protected $releephProjectID;
  protected $isActive;
  protected $createdByUserPHID;

  // The immutable name of this branch ('releases/foo-2013.01.24')
  protected $name;
  protected $basename;

  // The symbolic name of this branch (LATEST, PRODUCTION, RC, ...)
  // See SYMBOLIC_NAME_NOTE below
  protected $symbolicName;

  // Where to cut the branch
  protected $cutPointCommitPHID;

  protected $details = array();

  private $project = self::ATTACHABLE;
  private $cutPointCommit = self::ATTACHABLE;

  public function getConfiguration() {
    return array(
      self::CONFIG_AUX_PHID => true,
      self::CONFIG_SERIALIZATION => array(
        'details' => self::SERIALIZATION_JSON,
      ),
    ) + parent::getConfiguration();
  }

  public function generatePHID() {
    return PhabricatorPHID::generateNewPHID(ReleephPHIDTypeBranch::TYPECONST);
  }

  public function getDetail($key, $default = null) {
    return idx($this->getDetails(), $key, $default);
  }

  public function setDetail($key, $value) {
    $this->details[$key] = $value;
    return $this;
  }

  public function willWriteData(array &$data) {
    // If symbolicName is omitted, set it to the basename.
    //
    // This means that we can enforce symbolicName as a UNIQUE column in the
    // DB.  We'll interpret symbolicName === basename as meaning "no symbolic
    // name".
    //
    // SYMBOLIC_NAME_NOTE
    if (!$data['symbolicName']) {
      $data['symbolicName'] = $data['basename'];
    }
    parent::willWriteData($data);
  }

  public function getSymbolicName() {
    // See SYMBOLIC_NAME_NOTE above for why this is needed
    if ($this->symbolicName == $this->getBasename()) {
      return '';
    }
    return $this->symbolicName;
  }

  public function setSymbolicName($name) {
    if ($name) {
      parent::setSymbolicName($name);
    } else {
      parent::setSymbolicName($this->getBasename());
    }
    return $this;
  }

  public function getDisplayName() {
    if ($sn = $this->getSymbolicName()) {
      return $sn;
    }
    return $this->getBasename();
  }

  public function getDisplayNameWithDetail() {
    $n = $this->getBasename();
    if ($sn = $this->getSymbolicName()) {
      return "{$sn} ({$n})";
    } else {
      return $n;
    }
  }

  public function getURI($path = null) {
    $components = array(
      '/releeph',
      rawurlencode($this->loadReleephProject()->getName()),
      rawurlencode($this->getBasename()),
      $path
    );
    return implode('/', $components);
  }

  public function loadReleephProject() {
    return $this->loadOneRelative(
      new ReleephProject(),
      'id',
      'getReleephProjectID');
  }

  private function loadReleephRequestHandles(PhabricatorUser $user, $reqs) {
    $phids_to_phetch = array();
    foreach ($reqs as $rr) {
      $phids_to_phetch[] = $rr->getRequestCommitPHID();
      $phids_to_phetch[] = $rr->getRequestUserPHID();
      $phids_to_phetch[] = $rr->getCommitPHID();

      $intents = $rr->getUserIntents();
      if ($intents) {
        foreach ($intents as $user_phid => $intent) {
          $phids_to_phetch[] = $user_phid;
        }
      }

      $request_commit = $rr->loadPhabricatorRepositoryCommit();
      if ($request_commit) {
        $phids_to_phetch[] = $request_commit->getAuthorPHID();
        $phids_to_phetch[] = $rr->loadRequestCommitDiffPHID();
      }
    }
    $handles = id(new PhabricatorHandleQuery())
      ->setViewer($user)
      ->withPHIDs($phids_to_phetch)
      ->execute();
    return $handles;
  }

  public function populateReleephRequestHandles(PhabricatorUser $user, $reqs) {
    $handles = $this->loadReleephRequestHandles($user, $reqs);
    foreach ($reqs as $req) {
      $req->setHandles($handles);
    }
  }

  public function loadReleephRequests(PhabricatorUser $user) {
    $reqs = $this->loadRelatives(new ReleephRequest(), 'branchID');
    $this->populateReleephRequestHandles($user, $reqs);
    return $reqs;
  }

  public function isActive() {
    return $this->getIsActive();
  }

  public function attachProject(ReleephProject $project) {
    $this->project = $project;
    return $this;
  }

  public function getProject() {
    return $this->assertAttached($this->project);
  }

  public function attachCutPointCommit(
    PhabricatorRepositoryCommit $commit = null) {
    $this->cutPointCommit = $commit;
    return $this;
  }

  public function getCutPointCommit() {
    return $this->assertAttached($this->cutPointCommit);
  }


/* -(  PhabricatorPolicyInterface  )----------------------------------------- */


  public function getCapabilities() {
    return $this->getProject()->getCapabilities();
  }

  public function getPolicy($capability) {
    return $this->getProject()->getPolicy($capability);
  }

  public function hasAutomaticCapability($capability, PhabricatorUser $viewer) {
    return $this->getProject()->hasAutomaticCapability($capability, $viewer);
  }

}
