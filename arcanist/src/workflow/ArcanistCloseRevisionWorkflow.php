<?php

/**
 * Explicitly closes Differential revisions.
 *
 * @group workflow
 */
final class ArcanistCloseRevisionWorkflow extends ArcanistBaseWorkflow {

  public function getWorkflowName() {
    return 'close-revision';
  }

  public function getCommandSynopses() {
    return phutil_console_format(<<<EOTEXT
      **close-revision** [__options__] __revision__
EOTEXT
      );
  }

  public function getCommandHelp() {
    return phutil_console_format(<<<EOTEXT
          Supports: git, hg, svn
          Close a revision which has been committed (svn) or pushed (git, hg).
          You should not normally need to do this: arc commit (svn), arc amend
          (git), arc land (git), or repository tracking on the master remote
          repository should do it for you. However, if these mechanisms have
          failed for some reason you can use this command to manually change a
          revision status from "Accepted" to "Closed".
EOTEXT
      );
  }

  public function getArguments() {
    return array(
      'finalize' => array(
        'help' =>
          "Close only if the repository is untracked and the revision is ".
          "accepted. Continue even if the close can't happen. This is a soft ".
          "version of 'close-revision' used by other workflows.",
      ),
      'quiet' => array(
        'help' =>  'Do not print a success message.',
      ),
      '*' => 'revision',
    );
  }

  public function requiresConduit() {
    return true;
  }

  public function requiresAuthentication() {
    return true;
  }

  public function requiresRepositoryAPI() {
    // NOTE: Technically we only use this to generate the right message at
    // the end, and you can even get the wrong message (e.g., if you run
    // "arc close-revision D123" from a git repository, but D123 is an SVN
    // revision). We could be smarter about this, but it's just display fluff.
    return true;
  }

  public function run() {
    $is_finalize = $this->getArgument('finalize');

    $conduit = $this->getConduit();

    $revision_list = $this->getArgument('revision', array());
    if (!$revision_list) {
      throw new ArcanistUsageException(
        "close-revision requires a revision number.");
    }
    if (count($revision_list) != 1) {
      throw new ArcanistUsageException(
        "close-revision requires exactly one revision.");
    }
    $revision_id = reset($revision_list);
    $revision_id = $this->normalizeRevisionID($revision_id);

    $revision = null;
    try {
      $revision = $conduit->callMethodSynchronous(
        'differential.getrevision',
        array(
          'revision_id' => $revision_id,
        )
      );
    } catch (Exception $ex) {
      if (!$is_finalize) {
        throw new ArcanistUsageException(
          "Revision D{$revision_id} does not exist."
        );
      }
    }

    $status_accepted = ArcanistDifferentialRevisionStatus::ACCEPTED;
    $status_closed   = ArcanistDifferentialRevisionStatus::CLOSED;

    if (!$is_finalize && $revision['status'] != $status_accepted) {
      throw new ArcanistUsageException(
        "Revision D{$revision_id} can not be closed. You can only close ".
        "revisions which have been 'accepted'.");
    }

    if ($revision) {
      if (!$is_finalize && $revision['authorPHID'] != $this->getUserPHID()) {
        $prompt = "You are not the author of revision D{$revision_id}, ".
          'are you sure you want to close it?';
        if (!phutil_console_confirm($prompt)) {
          throw new ArcanistUserAbortException();
        }
      }

      $actually_close = true;
      if ($is_finalize) {
        $project_info = $this->getProjectInfo();
        if (idx($project_info, 'tracked') ||
            $revision['status'] != $status_accepted) {
          $actually_close = false;
        }
      }
      if ($actually_close) {
        $revision_name = $revision['title'];

        echo "Closing revision D{$revision_id} '{$revision_name}'...\n";

        $conduit->callMethodSynchronous(
          'differential.close',
          array(
            'revisionID' => $revision_id,
          ));
      }
    }

    $status = $revision['status'];
    if ($status == $status_accepted || $status == $status_closed) {
      // If this has already been attached to commits, don't show the
      // "you can push this commit" message since we know it's been pushed
      // already.
      $is_finalized = empty($revision['commits']);
    } else {
      $is_finalized = false;
    }

    if (!$this->getArgument('quiet')) {
      if ($is_finalized) {
        $message = $this->getRepositoryAPI()->getFinalizedRevisionMessage();
        echo phutil_console_wrap($message)."\n";
      } else {
        echo "Done.\n";
      }
    }

    return 0;
  }
}
