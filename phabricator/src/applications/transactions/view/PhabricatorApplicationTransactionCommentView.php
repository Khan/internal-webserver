<?php

/**
 * @concrete-extensible
 */
class PhabricatorApplicationTransactionCommentView extends AphrontView {

  private $submitButtonName;
  private $action;

  private $previewPanelID;
  private $previewTimelineID;
  private $previewToggleID;
  private $formID;
  private $statusID;
  private $commentID;
  private $draft;

  public function setDraft(PhabricatorDraft $draft) {
    $this->draft = $draft;
    return $this;
  }

  public function getDraft() {
    return $this->draft;
  }

  public function setSubmitButtonName($submit_button_name) {
    $this->submitButtonName = $submit_button_name;
    return $this;
  }

  public function getSubmitButtonName() {
    return $this->submitButtonName;
  }

  public function setAction($action) {
    $this->action = $action;
    return $this;
  }

  public function getAction() {
    return $this->action;
  }

  public function render() {

    $data = array();

    $comment = $this->renderCommentPanel();

    $preview = $this->renderPreviewPanel();

    Javelin::initBehavior(
      'phabricator-transaction-comment-form',
      array(
        'formID'        => $this->getFormID(),
        'timelineID'    => $this->getPreviewTimelineID(),
        'panelID'       => $this->getPreviewPanelID(),
        'statusID'      => $this->getStatusID(),
        'commentID'     => $this->getCommentID(),

        'loadingString' => pht('Loading Preview...'),
        'savingString'  => pht('Saving Draft...'),
        'draftString'   => pht('Saved Draft'),

        'actionURI'     => $this->getAction(),
        'draftKey'      => $this->getDraft()->getDraftKey(),
      ));

    return self::renderSingleView(
      array(
        $comment,
        $preview,
      ));
  }

  private function renderCommentPanel() {
    $status = phutil_tag(
      'div',
      array(
        'id' => $this->getStatusID(),
      ),
      '');

    $draft_comment = '';
    if ($this->getDraft()) {
      $draft_comment = $this->getDraft()->getDraft();
    }

    return id(new AphrontFormView())
      ->setUser($this->getUser())
      ->setFlexible(true)
      ->addSigil('transaction-append')
      ->setWorkflow(true)
      ->setAction($this->getAction())
      ->setID($this->getFormID())
      ->appendChild(
        id(new PhabricatorRemarkupControl())
          ->setID($this->getCommentID())
          ->setName('comment')
          ->setLabel(pht('Comment'))
          ->setUser($this->getUser())
          ->setValue($draft_comment))
      ->appendChild(
        id(new AphrontFormSubmitControl())
          ->setValue($this->getSubmitButtonName()))
      ->appendChild(
        id(new AphrontFormMarkupControl())
          ->setValue($status));
  }

  private function renderPreviewPanel() {

    $preview = id(new PhabricatorTimelineView())
      ->setID($this->getPreviewTimelineID());

    $header = phutil_tag(
      'div',
      array(
        'class' => 'phabricator-timeline-preview-header',
      ),
      pht('Preview'));

    return phutil_tag(
      'div',
      array(
        'id'    => $this->getPreviewPanelID(),
        'style' => 'display: none',
      ),
      self::renderHTMLView(
        array(
          $header,
          $preview,
        )));
  }

  private function getPreviewPanelID() {
    if (!$this->previewPanelID) {
      $this->previewPanelID = celerity_generate_unique_node_id();
    }
    return $this->previewPanelID;
  }

  private function getPreviewTimelineID() {
    if (!$this->previewTimelineID) {
      $this->previewTimelineID = celerity_generate_unique_node_id();
    }
    return $this->previewTimelineID;
  }

  private function getFormID() {
    if (!$this->formID) {
      $this->formID = celerity_generate_unique_node_id();
    }
    return $this->formID;
  }

  private function getStatusID() {
    if (!$this->statusID) {
      $this->statusID = celerity_generate_unique_node_id();
    }
    return $this->statusID;
  }

  private function getCommentID() {
    if (!$this->commentID) {
      $this->commentID = celerity_generate_unique_node_id();
    }
    return $this->commentID;
  }

}

