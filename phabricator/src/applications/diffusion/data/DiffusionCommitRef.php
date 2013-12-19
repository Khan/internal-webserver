<?php

final class DiffusionCommitRef extends Phobject {

  private $message;
  private $authorName;
  private $authorEmail;
  private $committerName;
  private $committerEmail;

  public function setCommitterEmail($committer_email) {
    $this->committerEmail = $committer_email;
    return $this;
  }

  public function getCommitterEmail() {
    return $this->committerEmail;
  }


  public function setCommitterName($committer_name) {
    $this->committerName = $committer_name;
    return $this;
  }

  public function getCommitterName() {
    return $this->committerName;
  }


  public function setAuthorEmail($author_email) {
    $this->authorEmail = $author_email;
    return $this;
  }

  public function getAuthorEmail() {
    return $this->authorEmail;
  }


  public function setAuthorName($author_name) {
    $this->authorName = $author_name;
    return $this;
  }

  public function getAuthorName() {
    return $this->authorName;
  }

  public function setMessage($message) {
    $this->message = $message;
    return $this;
  }

  public function getMessage() {
    return $this->message;
  }

  public function getAuthor() {
    return $this->formatUser($this->authorName, $this->authorEmail);
  }

  public function getCommitter() {
    return $this->formatUser($this->committerName, $this->committerEmail);
  }

  private function formatUser($name, $email) {
    if (strlen($name) && strlen($email)) {
      return "{$name} <{$email}>";
    } else if (strlen($email)) {
      return $email;
    } else if (strlen($name)) {
      return $name;
    } else {
      return null;
    }
  }

}
