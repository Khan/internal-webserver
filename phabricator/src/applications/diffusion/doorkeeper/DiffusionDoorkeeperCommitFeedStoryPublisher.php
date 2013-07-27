<?php

final class DiffusionDoorkeeperCommitFeedStoryPublisher
  extends DoorkeeperFeedStoryPublisher {

  private $auditRequests;
  private $activePHIDs;
  private $passivePHIDs;

  private function getAuditRequests() {
    return $this->auditRequests;
  }

  public function canPublishStory(PhabricatorFeedStory $story, $object) {
    return ($object instanceof PhabricatorRepositoryCommit);
  }

  public function willPublishStory($commit) {
    $requests = id(new PhabricatorAuditQuery())
      ->withCommitPHIDs(array($commit->getPHID()))
      ->execute();

    // TODO: This is messy and should be generalized, but we don't have a good
    // query for it yet. Since we run in the daemons, just do the easiest thing
    // we can for the moment. Figure out who all of the "active" (need to
    // audit) and "passive" (no action necessary) user are.

    $auditor_phids = mpull($requests, 'getAuditorPHID');
    $objects = id(new PhabricatorObjectHandleData($auditor_phids))
      ->setViewer($this->getViewer())
      ->loadObjects();

    $active = array();
    $passive = array();

    foreach ($requests as $request) {
      $status = $request->getAuditStatus();
      if ($status == PhabricatorAuditStatusConstants::CC) {
        // We handle these specially below.
        continue;
      }

      $object = idx($objects, $request->getAuditorPHID());
      if (!$object) {
        continue;
      }

      $request_phids = array();
      if ($object instanceof PhabricatorUser) {
        $request_phids = array($object->getPHID());
      } else if ($object instanceof PhabricatorOwnersPackage) {
        $request_phids = PhabricatorOwnersOwner::loadAffiliatedUserPHIDs(
          array($object->getID()));
      } else if ($object instanceof PhabricatorProject) {
        $request_phids = $object->loadMemberPHIDs();
      } else {
        // Dunno what this is.
        $request_phids = array();
      }

      switch ($status) {
        case PhabricatorAuditStatusConstants::AUDIT_REQUIRED:
        case PhabricatorAuditStatusConstants::AUDIT_REQUESTED:
        case PhabricatorAuditStatusConstants::CONCERNED:
          $active += array_fuse($request_phids);
          break;
        default:
          $passive += array_fuse($request_phids);
          break;
      }
    }



    // Remove "Active" users from the "Passive" list.
    $passive = array_diff_key($passive, $active);

    $this->activePHIDs = $active;
    $this->passivePHIDs = $passive;
    $this->auditRequests = $requests;

    return $commit;
  }

  public function getOwnerPHID($object) {
    return $object->getAuthorPHID();
  }

  public function getActiveUserPHIDs($object) {
    return $this->activePHIDs;
  }

  public function getPassiveUserPHIDs($object) {
    return $this->passivePHIDs;
  }

  public function getCCUserPHIDs($object) {
    $ccs = array();
    foreach ($this->getAuditRequests() as $request) {
      if ($request->getAuditStatus() == PhabricatorAuditStatusConstants::CC) {
        $ccs[] = $request->getAuditorPHID();
      }
    }
    return $ccs;
  }

  public function getObjectTitle($object) {
    $prefix = $this->getTitlePrefix($object);

    $repository = $object->getRepository();
    $name = $repository->formatCommitName($object->getCommitIdentifier());

    $title = $object->getSummary();

    return ltrim("{$prefix} {$name}: {$title}");
  }

  public function getObjectURI($object) {
    $repository = $object->getRepository();
    $name = $repository->formatCommitName($object->getCommitIdentifier());
    return PhabricatorEnv::getProductionURI('/'.$name);
  }

  public function getObjectDescription($object) {
    $data = $object->loadCommitData();
    if ($data) {
      return $data->getCommitMessage();
    }
    return null;
  }

  public function isObjectClosed($object) {
    switch ($object->getAuditStatus()) {
      case PhabricatorAuditCommitStatusConstants::NEEDS_AUDIT:
      case PhabricatorAuditCommitStatusConstants::CONCERN_RAISED:
      case PhabricatorAuditCommitStatusConstants::PARTIALLY_AUDITED:
        return false;
      default:
        return true;
    }
  }

  public function getResponsibilityTitle($object) {
    $prefix = $this->getTitlePrefix($object);
    return pht('%s Audit', $prefix);
  }

  public function getStoryText($object) {
    $story = $this->getFeedStory();
    if ($story instanceof PhabricatorFeedStoryAudit) {
      $text = $story->renderForAsanaBridge();
    } else {
      $text = $story->renderText();
    }
    return $text;
  }

  private function getTitlePrefix(PhabricatorRepositoryCommit $commit) {
    $prefix_key = 'metamta.diffusion.subject-prefix';
    return PhabricatorEnv::getEnvConfig($prefix_key);
  }

}
