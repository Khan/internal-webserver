<?php

final class DifferentialInlineComment
  implements PhabricatorInlineCommentInterface {

  private $proxy;
  private $syntheticAuthor;

  public function __construct() {
    $this->proxy = new DifferentialTransactionComment();
  }

  public function __clone() {
    $this->proxy = clone $this->proxy;
  }

  public function getTransactionCommentForSave() {
    $content_source = PhabricatorContentSource::newForSource(
      PhabricatorContentSource::SOURCE_LEGACY,
      array());

    $this->proxy
      ->setViewPolicy('public')
      ->setEditPolicy($this->getAuthorPHID())
      ->setContentSource($content_source)
      ->setCommentVersion(1);

    return $this->proxy;
  }

  public function openTransaction() {
    $this->proxy->openTransaction();
  }

  public function saveTransaction() {
    $this->proxy->saveTransaction();
  }

  public function save() {
    $this->getTransactionCommentForSave()->save();

    return $this;
  }

  public function delete() {
    $this->proxy->delete();

    return $this;
  }

  public function getID() {
    return $this->proxy->getID();
  }

  public function getPHID() {
    return $this->proxy->getPHID();
  }

  public static function newFromModernComment(
    DifferentialTransactionComment $comment) {

    $obj = new DifferentialInlineComment();
    $obj->proxy = $comment;

    return $obj;
  }

  public function setSyntheticAuthor($synthetic_author) {
    $this->syntheticAuthor = $synthetic_author;
    return $this;
  }

  public function getSyntheticAuthor() {
    return $this->syntheticAuthor;
  }

  public function isCompatible(PhabricatorInlineCommentInterface $comment) {
    return
      ($this->getAuthorPHID() === $comment->getAuthorPHID()) &&
      ($this->getSyntheticAuthor() === $comment->getSyntheticAuthor()) &&
      ($this->getContent() === $comment->getContent());
  }

  public function setContent($content) {
    $this->proxy->setContent($content);
    return $this;
  }

  public function getContent() {
    return $this->proxy->getContent();
  }

  public function isDraft() {
    return !$this->proxy->getTransactionPHID();
  }

  public function setChangesetID($id) {
    $this->proxy->setChangesetID($id);
    return $this;
  }

  public function getChangesetID() {
    return $this->proxy->getChangesetID();
  }

  public function setIsNewFile($is_new) {
    $this->proxy->setIsNewFile($is_new);
    return $this;
  }

  public function getIsNewFile() {
    return $this->proxy->getIsNewFile();
  }

  public function setLineNumber($number) {
    $this->proxy->setLineNumber($number);
    return $this;
  }

  public function getLineNumber() {
    return $this->proxy->getLineNumber();
  }

  public function setLineLength($length) {
    $this->proxy->setLineLength($length);
    return $this;
  }

  public function getLineLength() {
    return $this->proxy->getLineLength();
  }

  public function setCache($cache) {
    return $this;
  }

  public function getCache() {
    return null;
  }

  public function setAuthorPHID($phid) {
    $this->proxy->setAuthorPHID($phid);
    return $this;
  }

  public function getAuthorPHID() {
    return $this->proxy->getAuthorPHID();
  }

  public function setRevision(DifferentialRevision $revision) {
    $this->proxy->setRevisionPHID($revision->getPHID());
    return $this;
  }

  public function getRevisionPHID() {
    return $this->proxy->getRevisionPHID();
  }

  // Although these are purely transitional, they're also *extra* dumb.

  public function setRevisionID($revision_id) {
    $revision = id(new DifferentialRevision())->load($revision_id);
    return $this->setRevision($revision);
  }

  public function getRevisionID() {
    $phid = $this->proxy->getRevisionPHID();
    if (!$phid) {
      return null;
    }

    $revision = id(new DifferentialRevision())->loadOneWhere(
      'phid = %s',
      $phid);
    if (!$revision) {
      return null;
    }
    return $revision->getID();
  }

  // When setting a comment ID, we also generate a phantom transaction PHID for
  // the future transaction.

  public function setCommentID($id) {
    $this->proxy->setTransactionPHID(
      PhabricatorPHID::generateNewPHID(
        PhabricatorApplicationTransactionPHIDTypeTransaction::TYPECONST,
        DifferentialPHIDTypeRevision::TYPECONST));
    return $this;
  }

/* -(  PhabricatorMarkupInterface Implementation  )-------------------------- */


  public function getMarkupFieldKey($field) {
    // We can't use ID because synthetic comments don't have it.
    return 'DI:'.PhabricatorHash::digest($this->getContent());
  }

  public function newMarkupEngine($field) {
    return PhabricatorMarkupEngine::newDifferentialMarkupEngine();
  }

  public function getMarkupText($field) {
    return $this->getContent();
  }

  public function didMarkupText($field, $output, PhutilMarkupEngine $engine) {
    return $output;
  }

  public function shouldUseMarkupCache($field) {
    // Only cache submitted comments.
    return ($this->getID() && !$this->isDraft());
  }

}
