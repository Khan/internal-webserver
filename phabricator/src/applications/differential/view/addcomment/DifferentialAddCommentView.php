<?php

/*
 * Copyright 2012 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

final class DifferentialAddCommentView extends AphrontView {

  private $revision;
  private $actions;
  private $actionURI;
  private $user;
  private $draft;
  private $auxFields;

  public function setRevision($revision) {
    $this->revision = $revision;
    return $this;
  }

  public function setAuxFields(array $aux_fields) {
    assert_instances_of($aux_fields, 'DifferentialFieldSpecification');
    $this->auxFields = $aux_fields;
    return $this;
  }

  public function setActions(array $actions) {
    $this->actions = $actions;
    return $this;
  }

  public function setActionURI($uri) {
    $this->actionURI = $uri;
    return $this;
  }

  public function setUser(PhabricatorUser $user) {
    $this->user = $user;
    return $this;
  }

  public function setDraft($draft) {
    $this->draft = $draft;
    return $this;
  }

  private function generateWarningView(
    $status,
    array $titles,
    $id,
    $content) {

    $warning = new AphrontErrorView();
    $warning->setSeverity(AphrontErrorView::SEVERITY_ERROR);
    $warning->setWidth(AphrontErrorView::WIDTH_WIDE);
    $warning->setID($id);
    $warning->appendChild($content);
    $warning->setTitle(idx($titles, $status, 'Warning'));

    return $warning;
  }

  public function render() {

    require_celerity_resource('differential-revision-add-comment-css');

    $is_serious = PhabricatorEnv::getEnvConfig('phabricator.serious-business');

    $revision = $this->revision;

    $form = new AphrontFormView();
    $form
      ->setWorkflow(true)
      ->setUser($this->user)
      ->setAction($this->actionURI)
      ->addHiddenInput('revision_id', $revision->getID())
      ->appendChild(
        id(new AphrontFormSelectControl())
          ->setLabel('Action')
          ->setName('action')
          ->setID('comment-action')
          ->setOptions($this->actions))
      ->appendChild(
        id(new AphrontFormTokenizerControl())
          ->setLabel('Add Reviewers')
          ->setName('reviewers')
          ->setControlID('add-reviewers')
          ->setControlStyle('display: none')
          ->setID('add-reviewers-tokenizer')
          ->setDisableBehavior(true))
      ->appendChild(
        id(new AphrontFormTokenizerControl())
          ->setLabel('Add CCs')
          ->setName('ccs')
          ->setControlID('add-ccs')
          ->setControlStyle('display: none')
          ->setID('add-ccs-tokenizer')
          ->setDisableBehavior(true))
      ->appendChild(
        id(new AphrontFormTextAreaControl())
          ->setName('comment')
          ->setID('comment-content')
          ->setLabel('Comment')
          ->setEnableDragAndDropFileUploads(true)
          ->setValue($this->draft)
          ->setCaption(phutil_render_tag(
            'a',
            array(
              'href' => PhabricatorEnv::getDoclink(
                'article/Remarkup_Reference.html'),
              'tabindex' => '-1',
              'target' => '_blank',
            ),
            'Formatting Reference')))
      ->appendChild(
        id(new AphrontFormSubmitControl())
          ->setValue($is_serious ? 'Submit' : 'Clowncopterize'));

    Javelin::initBehavior(
      'differential-add-reviewers-and-ccs',
      array(
        'dynamic' => array(
          'add-reviewers-tokenizer' => array(
            'actions' => array('request_review' => 1, 'add_reviewers' => 1),
            'src' => '/typeahead/common/users/',
            'row' => 'add-reviewers',
            'ondemand' => PhabricatorEnv::getEnvConfig('tokenizer.ondemand'),
            'placeholder' => 'Type a user name...',
          ),
          'add-ccs-tokenizer' => array(
            'actions' => array('add_ccs' => 1),
            'src' => '/typeahead/common/mailable/',
            'row' => 'add-ccs',
            'ondemand' => PhabricatorEnv::getEnvConfig('tokenizer.ondemand'),
            'placeholder' => 'Type a user or mailing list...',
          ),
        ),
        'select' => 'comment-action',
      ));

    $diff = $revision->loadActiveDiff();
    $warnings = mpull($this->auxFields, 'renderWarningBoxForRevisionAccept');
    $lint_warning = null;
    $unit_warning = null;

    Javelin::initBehavior(
      'differential-accept-with-errors',
      array(
        'select' => 'comment-action',
        'warnings' => 'warnings',
      ));

    $rev_id = $revision->getID();

    Javelin::initBehavior(
      'differential-feedback-preview',
      array(
        'uri'       => '/differential/comment/preview/'.$rev_id.'/',
        'preview'   => 'comment-preview',
        'action'    => 'comment-action',
        'content'   => 'comment-content',
        'previewTokenizers' => array(
          'reviewers' => 'add-reviewers-tokenizer',
          'ccs'       => 'add-ccs-tokenizer',
        ),

        'inlineuri' => '/differential/comment/inline/preview/'.$rev_id.'/',
        'inline'    => 'inline-comment-preview',
      ));

    $panel_view = new AphrontPanelView();
    $panel_view->appendChild($form);
    $warning_container = '<div id="warnings">';
    foreach ($warnings as $warning) {
      if ($warning) {
        $warning_container .= $warning->render();
      }
    }
    $warning_container .= '</div>';
    $panel_view->appendChild($warning_container);
    if ($lint_warning) {
      $panel_view->appendChild($lint_warning);
    }
    if ($unit_warning) {
      $panel_view->appendChild($unit_warning);
    }

    $panel_view->setHeader($is_serious ? 'Add Comment' : 'Leap Into Action');

    $panel_view->addClass('aphront-panel-accent');
    $panel_view->addClass('aphront-panel-flush');

    return
      '<div class="differential-add-comment-panel">'.
        $panel_view->render().
        '<div class="aphront-panel-preview aphront-panel-flush">'.
          '<div id="comment-preview">'.
            '<span class="aphront-panel-preview-loading-text">'.
              'Loading comment preview...'.
            '</span>'.
          '</div>'.
          '<div id="inline-comment-preview">'.
          '</div>'.
        '</div>'.
      '</div>';
  }
}
