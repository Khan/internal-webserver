<?php

final class PhabricatorFeedStoryCommit extends PhabricatorFeedStory {

  public function getPrimaryObjectPHID() {
    return $this->getValue('commitPHID');
  }

  public function getRequiredHandlePHIDs() {
    return array(
      $this->getValue('committerPHID'),
    );
  }

  public function renderView() {
    $data = $this->getStoryData();

    $author = null;
    if ($data->getValue('authorPHID')) {
      $author = $this->linkTo($data->getValue('authorPHID'));
    } else {
      $author = phutil_escape_html($data->getValue('authorName'));
    }

    $committer = null;
    if ($data->getValue('committerPHID')) {
      $committer = $this->linkTo($data->getValue('committerPHID'));
    } else if ($data->getValue('committerName')) {
      $committer = phutil_escape_html($data->getValue('committerName'));
    }

    $commit = $this->linkTo($data->getValue('commitPHID'));

    if (!$committer) {
      $committer = $author;
      $author = null;
    }

    if ($author) {
      $title = "{$committer} committed {$commit} (authored by {$author})";
    } else {
      $title = "{$committer} committed {$commit}";
    }

    $view = new PhabricatorFeedStoryView();

    $view->setTitle($title);
    $view->setEpoch($data->getEpoch());

    if ($data->getValue('authorPHID')) {
      $view->setImage($this->getHandle($data->getAuthorPHID())->getImageURI());
    }

    $content = $this->renderSummary($data->getValue('summary'));
    $view->appendChild($content);

    return $view;
  }

  public function renderText() {
    $author = null;
    if ($this->getAuthorPHID()) {
      $author = $this->getHandle($this->getAuthorPHID())->getLinkName();
    } else {
      $author = $this->getValue('authorName');
    }

    $committer = null;
    if ($this->getValue('committerPHID')) {
      $committer_handle = $this->getHandle($this->getValue('committerPHID'));
      $committer = $committer_handle->getLinkName();
    } else if ($this->getValue('committerName')) {
      $committer = $this->getValue('committerName');
    }

    $commit_handle = $this->getHandle($this->getPrimaryObjectPHID());
    $commit_uri = PhabricatorEnv::getURI($commit_handle->getURI());
    $commit_name = $commit_handle->getLinkName();

    if (!$committer) {
      $committer = $author;
      $author = null;
    }

    if ($author) {
      $text = "{$committer} (authored by {$author})".
              "committed {$commit_name} {$commit_uri}";
    } else {
      $text = "{$committer} committed {$commit_name} {$commit_uri}";
    }

    return $text;
  }

}
