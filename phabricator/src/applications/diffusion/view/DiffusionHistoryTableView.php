<?php

final class DiffusionHistoryTableView extends DiffusionView {

  private $history;
  private $revisions = array();
  private $handles = array();
  private $isHead;
  private $parents;

  public function setHistory(array $history) {
    assert_instances_of($history, 'DiffusionPathChange');
    $this->history = $history;
    return $this;
  }

  public function loadRevisions() {
    $commit_phids = array();
    foreach ($this->history as $item) {
      if ($item->getCommit()) {
        $commit_phids[] = $item->getCommit()->getPHID();
      }
    }
    $this->revisions = id(new DifferentialRevision())
      ->loadIDsByCommitPHIDs($commit_phids);
    return $this;
  }

  public function setHandles(array $handles) {
    assert_instances_of($handles, 'PhabricatorObjectHandle');
    $this->handles = $handles;
    return $this;
  }

  public function getRequiredHandlePHIDs() {
    $phids = array();
    foreach ($this->history as $item) {
      $data = $item->getCommitData();
      if ($data) {
        if ($data->getCommitDetail('authorPHID')) {
          $phids[$data->getCommitDetail('authorPHID')] = true;
        }
        if ($data->getCommitDetail('committerPHID')) {
          $phids[$data->getCommitDetail('committerPHID')] = true;
        }
      }
    }
    return array_keys($phids);
  }

  public function setParents(array $parents) {
    $this->parents = $parents;
    return $this;
  }

  public function setIsHead($is_head) {
    $this->isHead = $is_head;
    return $this;
  }

  public function render() {
    $drequest = $this->getDiffusionRequest();

    $handles = $this->handles;

    $graph = null;
    if ($this->parents) {
      $graph = $this->renderGraph();
    }

    $rows = array();
    $ii = 0;
    foreach ($this->history as $history) {
      $epoch = $history->getEpoch();

      if ($epoch) {
        $date = phabricator_date($epoch, $this->user);
        $time = phabricator_time($epoch, $this->user);
      } else {
        $date = null;
        $time = null;
      }

      $data = $history->getCommitData();
      $author_phid = $committer = $committer_phid = null;
      if ($data) {
        $author_phid = $data->getCommitDetail('authorPHID');
        $committer_phid = $data->getCommitDetail('committerPHID');
        $committer = $data->getCommitDetail('committer');
      }

      if ($author_phid && isset($handles[$author_phid])) {
        $author = $handles[$author_phid]->renderLink();
      } else {
        $author = self::renderName($history->getAuthorName());
      }

      $different_committer = false;
      if ($committer_phid) {
        $different_committer = ($committer_phid != $author_phid);
      } else if ($committer != '') {
        $different_committer = ($committer != $history->getAuthorName());
      }
      if ($different_committer) {
        if ($committer_phid && isset($handles[$committer_phid])) {
          $committer = $handles[$committer_phid]->renderLink();
        } else {
          $committer = self::renderName($committer);
        }
        $author = hsprintf('%s/%s', $author, $committer);
      }

      $commit = $history->getCommit();
      if ($commit && !$commit->getIsUnparsed() && $data) {
        $change = $this->linkChange(
          $history->getChangeType(),
          $history->getFileType(),
          $path = null,
          $history->getCommitIdentifier());
      } else {
        $change = phutil_tag('em', array(), "Importing\xE2\x80\xA6");
      }

      $rows[] = array(
        $this->linkBrowse(
          $drequest->getPath(),
          array(
            'commit' => $history->getCommitIdentifier(),
          )),
        $graph ? $graph[$ii++] : null,
        self::linkCommit(
          $drequest->getRepository(),
          $history->getCommitIdentifier()),
        ($commit ?
          self::linkRevision(idx($this->revisions, $commit->getPHID())) :
          null),
        $change,
        $date,
        $time,
        $author,
        AphrontTableView::renderSingleDisplayLine($history->getSummary()),
        // TODO: etc etc
      );
    }

    $view = new AphrontTableView($rows);
    $view->setHeaders(
      array(
        'Browse',
        '',
        'Commit',
        'Revision',
        'Change',
        'Date',
        'Time',
        'Author/Committer',
        'Details',
      ));
    $view->setColumnClasses(
      array(
        '',
        'threads',
        'n',
        'n',
        '',
        '',
        'right',
        '',
        'wide',
      ));
    $view->setColumnVisibility(
      array(
        true,
        $graph ? true : false,
      ));
    return $view->render();
  }

  /**
   * Draw a merge/branch graph from the parent revision data. We're basically
   * building up a bunch of strings like this:
   *
   *  ^
   *  |^
   *  o|
   *  |o
   *  o
   *
   * ...which form an ASCII representation of the graph we eventaully want to
   * draw.
   *
   * NOTE: The actual implementation is black magic.
   */
  private function renderGraph() {

    // This keeps our accumulated information about each line of the
    // merge/branch graph.
    $graph = array();

    // This holds the next commit we're looking for in each column of the
    // graph.
    $threads = array();

    // This is the largest number of columns any row has, i.e. the width of
    // the graph.
    $count = 0;

    foreach ($this->history as $key => $history) {
      $joins = array();
      $splits = array();

      $parent_list = $this->parents[$history->getCommitIdentifier()];

      // Look for some thread which has this commit as the next commit. If
      // we find one, this commit goes on that thread. Otherwise, this commit
      // goes on a new thread.

      $line = '';
      $found = false;
      $pos = count($threads);
      for ($n = 0; $n < $count; $n++) {
        if (empty($threads[$n])) {
          $line .= ' ';
          continue;
        }

        if ($threads[$n] == $history->getCommitIdentifier()) {
          if ($found) {
            $line .= ' ';
            $joins[] = $n;
            unset($threads[$n]);
          } else {
            $line .= 'o';
            $found = true;
            $pos = $n;
          }
        } else {

          // We render a "|" for any threads which have a commit that we haven't
          // seen yet, this is later drawn as a vertical line.
          $line .= '|';
        }
      }

      // If we didn't find the thread this commit goes on, start a new thread.
      // We use "o" to mark the commit for the rendering engine, or "^" to
      // indicate that there's nothing after it so the line from the commit
      // upward should not be drawn.

      if (!$found) {
        if ($this->isHead) {
          $line .= '^';
        } else {
          $line .= 'o';
          foreach ($graph as $k => $meta) {
            // Go back across all the lines we've already drawn and add a
            // "|" to the end, since this is connected to some future commit
            // we don't know about.
            for ($jj = strlen($meta['line']); $jj <= $count; $jj++) {
              $graph[$k]['line'] .= '|';
            }
          }
        }
      }

      // Update the next commit on this thread to the commit's first parent.
      // This might have the effect of making a new thread.
      $threads[$pos] = head($parent_list);

      // If we made a new thread, increase the thread count.
      $count = max($pos + 1, $count);

      // Now, deal with splits (merges). I picked this terms opposite to the
      // underlying repository term to confuse you.
      foreach (array_slice($parent_list, 1) as $parent) {
        $found = false;

        // Try to find the other parent(s) in our existing threads. If we find
        // them, split to that thread.

        foreach ($threads as $idx => $thread_commit) {
          if ($thread_commit == $parent) {
            $found = true;
            $splits[] = $idx;
          }
        }

        // If we didn't find the parent, we don't know about it yet. Find the
        // first free thread and add it as the "next" commit in that thread.
        // This might create a new thread.

        if (!$found) {
          for ($n = 0; $n < $count; $n++) {
            if (empty($threads[$n])) {
              break;
            }
          }
          $threads[$n] = $parent;
          $splits[] = $n;
          $count = max($n + 1, $count);
        }
      }

      $graph[] = array(
        'line' => $line,
        'split' => $splits,
        'join' => $joins,
      );
    }

    // Render into tags for the behavior.

    foreach ($graph as $k => $meta) {
      $graph[$k] = javelin_tag(
        'div',
        array(
          'sigil' => 'commit-graph',
          'meta' => $meta,
        ),
        '');
    }

    Javelin::initBehavior(
      'diffusion-commit-graph',
      array(
        'count' => $count,
      ));

    return $graph;
  }

}
