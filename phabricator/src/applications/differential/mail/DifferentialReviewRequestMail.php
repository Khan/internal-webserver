<?php

abstract class DifferentialReviewRequestMail extends DifferentialMail {

  const MAX_AFFECTED_FILES = 1000;

  protected $comments;

  private $patch;

  public function setComments($comments) {
    $this->comments = $comments;
    return $this;
  }

  public function getComments() {
    return $this->comments;
  }

  public function __construct(
    DifferentialRevision $revision,
    PhabricatorObjectHandle $actor,
    array $changesets) {
    assert_instances_of($changesets, 'DifferentialChangeset');

    $this->setRevision($revision);
    $this->setActorHandle($actor);
    $this->setChangesets($changesets);
  }

  protected function prepareBody() {
    parent::prepareBody();

    $inline_max_length = PhabricatorEnv::getEnvConfig(
      'metamta.differential.inline-patches');
    if ($inline_max_length) {
      $patch = $this->buildPatch();
      if (count(explode("\n", $patch)) <= $inline_max_length) {
        $this->patch = $patch;
      }
    }
  }

  protected function renderReviewRequestBody() {
    $revision = $this->getRevision();

    $body = array();
    if (!$this->isFirstMailToRecipients()) {
      if (strlen($this->getComments())) {
        $body[] = $this->formatText($this->getComments());
        $body[] = null;
      }
    }

    $phase = ($this->isFirstMailToRecipients() ?
      DifferentialMailPhase::WELCOME :
      DifferentialMailPhase::UPDATE);
    $body[] = $this->renderAuxFields($phase);

    $changesets = $this->getChangesets();
    if ($changesets) {
      $body[] = 'AFFECTED FILES';
      $max = self::MAX_AFFECTED_FILES;
      foreach (array_values($changesets) as $i => $changeset) {
        if ($i == $max) {
          $body[] = '  ('.(count($changesets) - $max).' more files)';
          break;
        }
        $body[] = '  '.$changeset->getFilename();
      }
      $body[] = null;
    }

    if ($this->patch) {
      $body[] = 'CHANGE DETAILS';
      $body[] = $this->patch;
    }

    return implode("\n", $body);
  }

  protected function buildAttachments() {
    $attachments = array();

    if (PhabricatorEnv::getEnvConfig('metamta.differential.attach-patches')) {

      $revision = $this->getRevision();
      $revision_id = $revision->getID();

      $diffs = id(new DifferentialDiffQuery())
        ->setViewer($this->getActor())
        ->withRevisionIDs(array($revision_id))
        ->execute();
      $diff_number = count($diffs);

      $attachments[] = new PhabricatorMetaMTAAttachment(
        $this->buildPatch(),
        "D{$revision_id}.{$diff_number}.patch",
        'text/x-patch; charset=utf-8'
      );
    }

    return $attachments;
  }

  public function loadFileByPHID($phid) {
    // TODO: (T603) Factor this and the other one out.
    $file = id(new PhabricatorFile())->loadOneWhere(
      'phid = %s',
      $phid);
    if (!$file) {
      return null;
    }
    return $file->loadFileData();
  }

  private function buildPatch() {
    $diff = new DifferentialDiff();
    $diff->attachChangesets($this->getChangesets());
    foreach ($diff->getChangesets() as $changeset) {
      $changeset->attachHunks(
        $changeset->loadRelatives(new DifferentialHunk(), 'changesetID'));
    }

    $raw_changes = $diff->buildChangesList();
    $changes = array();
    foreach ($raw_changes as $changedict) {
      $changes[] = ArcanistDiffChange::newFromDictionary($changedict);
    }

    // TODO: It would be nice to have a real viewer here eventually, but
    // in the meantime anyone we're sending mail to can certainly see the
    // patch.
    $loader = id(new PhabricatorFileBundleLoader())
      ->setViewer(PhabricatorUser::getOmnipotentUser());

    $bundle = ArcanistBundle::newFromChanges($changes);
    $bundle->setLoadFileDataCallback(array($loader, 'loadFileData'));

    $format = PhabricatorEnv::getEnvConfig('metamta.differential.patch-format');
    switch ($format) {
      case 'git':
        return $bundle->toGitPatch();
        break;
      case 'unified':
      default:
        return $bundle->toUnifiedDiff();
        break;
    }
  }

  protected function getMailTags() {
    $tags = array();
    if ($this->isFirstMailToRecipients()) {
      $tags[] = MetaMTANotificationType::TYPE_DIFFERENTIAL_REVIEW_REQUEST;
    } else {
      $tags[] = MetaMTANotificationType::TYPE_DIFFERENTIAL_UPDATED;
    }
    return $tags;
  }

}
