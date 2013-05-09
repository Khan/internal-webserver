<?php

final class ConduitAPI_releephwork_nextrequest_Method
  extends ConduitAPI_releeph_Method {

  private $project;
  private $branch;

  public function getMethodStatus() {
    return self::METHOD_STATUS_UNSTABLE;
  }

  public function getMethodDescription() {
    return
      "Return info required to cut a branch, ".
      "and pick and revert ReleephRequests";
  }

  public function defineParamTypes() {
    return array(
      'branchPHID'  => 'required int',
      'seen'        => 'required list<string, bool>',
    );
  }

  public function defineReturnType() {
    return '';
  }

  public function defineErrorTypes() {
    return array(
      'ERR-NOT-PUSHER' =>
        'You are not listed as a pusher for thie Releeph project!',
    );
  }

  protected function execute(ConduitAPIRequest $request) {
    $seen = $request->getValue('seen');

    $branch = id(new ReleephBranch())
      ->loadOneWhere('phid = %s', $request->getValue('branchPHID'));

    $project = $branch->loadReleephProject();

    $needs_pick = array();
    $needs_revert = array();

    $releeph_requests = $branch->loadReleephRequests($request->getUser());

    foreach ($releeph_requests as $candidate) {
      $phid = $candidate->getPHID();
      if (idx($seen, $phid)) {
        continue;
      }

      $should = $candidate->shouldBeInBranch();
      $in = $candidate->getInBranch();
      if ($should && !$in) {
        $needs_pick[] = $candidate;
      }
      if (!$should && $in) {
        $needs_revert[] = $candidate;
      }
    }

    /**
     * Sort both needs_pick and needs_revert in ascending commit order, as
     * discovered by Phabricator (using the `id` column to perform that
     * ordering).
     *
     * This is easy for $needs_pick as the ordinal is stored.  It is hard for
     * reverts, as we have to look that information up.
     */
    $needs_pick = $this->sortPicks($needs_pick);
    $needs_revert = $this->sortReverts($needs_revert);

    /**
     * Do reverts first in reverse order, then the picks in original-commit
     * order.
     *
     * This seems like the correct thing to do, but there may be a better
     * algorithm for the releephwork.nextrequest Conduit call that orders
     * things better.
     *
     * We could also button-mash our way through everything that failed (at the
     * end of the run) to try failed things again.
     */
    $releeph_request = null;
    $action = null;
    if ($needs_revert) {
      $releeph_request = last($needs_revert);
      $action = 'revert';
      $commit_id = $releeph_request->getCommitIdentifier();
      $commit_phid = $releeph_request->getCommitPHID();
    } elseif ($needs_pick) {
      $releeph_request = head($needs_pick);
      $action = 'pick';
      $commit = $releeph_request->loadPhabricatorRepositoryCommit();
      $commit_id = $commit->getCommitIdentifier();
      $commit_phid = $commit->getPHID();
    } else {
      // Return early if there's nothing to do!
      return array();
    }

    // Build the response
    $phids = array();
    $phids[] = $commit_phid;

    $diff_phid = null;
    $diff_rev_id = null;
    $diff_rev = $releeph_request->loadDifferentialRevision();
    if ($diff_rev) {
      $diff_phid = $diff_rev->getPHID();
      $phids[] = $diff_phid;
      $diff_rev_id = $diff_rev->getID();
    }

    $phids[] = $releeph_request->getPHID();
    $handles = id(new PhabricatorObjectHandleData($phids))
      ->setViewer($request->getUser())
      ->loadHandles();

    $diff_name = null;
    if ($diff_rev) {
      $diff_name = $handles[$diff_phid]->getName();
    }

    // Calculate the new-author information (if any)
    $new_author = null;
    $new_author_phid = null;
    switch ($project->getDetail('commitWithAuthor')) {
      case ReleephProject::COMMIT_AUTHOR_NONE:
        break;

      case ReleephProject::COMMIT_AUTHOR_FROM_DIFF:
        if ($diff_rev) {
          $new_author_phid = $diff_rev->getAuthorPHID();
        } else {
          $pr_commit = $releeph_request->loadPhabricatorRepositoryCommit();
          if ($pr_commit) {
            $new_author_phid = $pr_commit->getAuthorPHID();
          }
        }
        break;

      case ReleephProject::COMMIT_AUTHOR_REQUESTOR:
        $new_author_phid = $releeph_request->getRequestUserPHID();
        break;
    }

    return array(
      'requestID'         => $releeph_request->getID(),
      'requestPHID'       => $releeph_request->getPHID(),
      'requestName'       => $handles[$releeph_request->getPHID()]->getName(),
      'requestorPHID'     => $releeph_request->getRequestUserPHID(),
      'action'            => $action,
      'diffRevID'         => $diff_rev_id,
      'diffName'          => $diff_name,
      'commitIdentifier'  => $commit_id,
      'commitPHID'        => $commit_phid,
      'commitName'        => $handles[$commit_phid]->getName(),
      'needsRevert'       => mpull($needs_revert, 'getID'),
      'needsPick'         => mpull($needs_pick, 'getID'),
      'newAuthorPHID'     => $new_author_phid,
    );
  }

  private function sortPicks(array $releeph_requests) {
    $surrogate = array();
    foreach ($releeph_requests as $rq) {
      // TODO: it's likely that relying on the `id` column to provide
      // trunk-commit-order is thoroughly broken.
      $ordinal = (int) $rq->loadPhabricatorRepositoryCommit()->getID();
      $surrogate[$ordinal] = $rq;
    }
    ksort($surrogate);
    return $surrogate;
  }

  /**
   * Sort an array of ReleephRequests, that have been picked into a branch, in
   * the order in which they were picked to the branch.
   */
  private function sortReverts(array $releeph_requests) {
    if (!$releeph_requests) {
      return array();
    }

    // ReleephRequests, keyed by <branch-commit-id>
    $releeph_requests = mpull($releeph_requests, null, 'getCommitIdentifier');

    $commits = id(new PhabricatorRepositoryCommit())
      ->loadAllWhere(
        'commitIdentifier IN (%Ls)',
        mpull($releeph_requests, 'getCommitIdentifier'));

    // A map of <branch-commit-id> => <branch-commit-ordinal>
    $surrogate = mpull($commits, 'getID', 'getCommitIdentifier');

    $unparsed = array();
    $result = array();

    foreach ($releeph_requests as $commit_id => $releeph_request) {
      $ordinal = idx($surrogate, $commit_id);
      if ($ordinal) {
        $result[$ordinal] = $releeph_request;
      } else {
        $unparsed[] = $releeph_request;
      }
    }

    // Sort $result in ascending order
    ksort($result);

    // Unparsed commits we'll just have to guess, based on time
    $unparsed = msort($unparsed, 'getDateModified');

    return array_merge($result, $unparsed);
  }
}
