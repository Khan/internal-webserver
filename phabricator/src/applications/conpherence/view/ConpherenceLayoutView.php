<?php

final class ConpherenceLayoutView extends AphrontView {

  private $thread;
  private $baseURI;
  private $threadView;
  private $role;
  private $header;
  private $messages;
  private $replyForm;

  public function setMessages($messages) {
    $this->messages = $messages;
    return $this;
  }

  public function setReplyForm($reply_form) {
    $this->replyForm = $reply_form;
    return $this;
  }

  public function setHeader($header) {
    $this->header = $header;
    return $this;
  }

  public function setRole($role) {
    $this->role = $role;
    return $this;
  }

  public function getThreadView() {
    return $this->threadView;
  }

  public function setBaseURI($base_uri) {
    $this->baseURI = $base_uri;
    return $this;
  }

  public function setThread(ConpherenceThread $thread) {
    $this->thread = $thread;
    return $this;
  }

  public function setThreadView(ConpherenceThreadListView $thead_view) {
    $this->threadView = $thead_view;
    return $this;
  }

  public function render() {
    require_celerity_resource('conpherence-menu-css');

    $layout_id = celerity_generate_unique_node_id();

    Javelin::initBehavior('conpherence-menu',
      array(
        'base_uri' => $this->baseURI,
        'header' => 'conpherence-header-pane',
        'messages' => 'conpherence-messages',
        'messages_pane' => 'conpherence-message-pane',
        'widgets_pane' => 'conpherence-widget-pane',
        'form_pane' => 'conpherence-form',
        'menu_pane' => 'conpherence-menu',
        'layoutID' => $layout_id,
        'selectedID' => ($this->thread ? $this->thread->getID() : null),
        'role' => $this->role,
        'hasThreadList' => (bool)$this->threadView,
        'hasThread' => (bool)$this->messages,
        'hasWidgets' => false,
      ));

    Javelin::initBehavior('conpherence-drag-and-drop-photo',
      array(
        'target' => 'conpherence-header-pane',
        'form_pane' => 'conpherence-form',
        'upload_uri' => '/file/dropupload/',
        'activated_class' => 'conpherence-header-upload-photo',
      ));

    return javelin_tag(
      'div',
      array(
        'id'    => $layout_id,
        'sigil' => 'conpherence-layout',
        'class' => 'conpherence-layout conpherence-role-'.$this->role,
      ),
      array(
        javelin_tag(
          'div',
          array(
            'class' => 'phabricator-nav-column-background',
          ),
          ''),
        javelin_tag(
          'div',
          array(
            'class' => 'conpherence-menu-pane phabricator-side-menu',
            'sigil' => 'conpherence-menu-pane',
          ),
          nonempty($this->threadView, '')),
        javelin_tag(
          'div',
          array(
            'class' => 'conpherence-content-pane',
          ),
          array(
            javelin_tag(
              'div',
              array(
                'class' => 'conpherence-header-pane',
                'id' => 'conpherence-header-pane',
                'sigil' => 'conpherence-header',
              ),
              nonempty($this->header, '')),
            phutil_tag(
              'div',
              array(
                'class' => 'conpherence-widget-pane',
                'id' => 'conpherence-widget-pane'
              ),
              ''),
            javelin_tag(
              'div',
              array(
                'class' => 'conpherence-message-pane',
                'id' => 'conpherence-message-pane'
              ),
              array(
                javelin_tag(
                  'div',
                  array(
                    'class' => 'conpherence-messages',
                    'id' => 'conpherence-messages',
                    'sigil' => 'conpherence-messages',
                  ),
                  nonempty($this->messages, '')),
                phutil_tag(
                  'div',
                  array(
                    'id' => 'conpherence-form'
                  ),
                  nonempty($this->replyForm, ''))
              )),
          )),
      ));
  }

}
