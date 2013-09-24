<?php

final class PhabricatorTimelineEventView extends AphrontView {

  private $userHandle;
  private $title;
  private $icon;
  private $color;
  private $classes = array();
  private $contentSource;
  private $dateCreated;
  private $anchor;
  private $isEditable;
  private $isEdited;
  private $transactionPHID;
  private $isPreview;
  private $eventGroup = array();

  public function setTransactionPHID($transaction_phid) {
    $this->transactionPHID = $transaction_phid;
    return $this;
  }

  public function getTransactionPHID() {
    return $this->transactionPHID;
  }

  public function setIsEdited($is_edited) {
    $this->isEdited = $is_edited;
    return $this;
  }

  public function getIsEdited() {
    return $this->isEdited;
  }

  public function setIsPreview($is_preview) {
    $this->isPreview = $is_preview;
    return $this;
  }

  public function getIsPreview() {
    return $this->isPreview;
  }

  public function setIsEditable($is_editable) {
    $this->isEditable = $is_editable;
    return $this;
  }

  public function getIsEditable() {
    return $this->isEditable;
  }

  public function setDateCreated($date_created) {
    $this->dateCreated = $date_created;
    return $this;
  }

  public function getDateCreated() {
    return $this->dateCreated;
  }

  public function setContentSource(PhabricatorContentSource $content_source) {
    $this->contentSource = $content_source;
    return $this;
  }

  public function getContentSource() {
    return $this->contentSource;
  }

  public function setUserHandle(PhabricatorObjectHandle $handle) {
    $this->userHandle = $handle;
    return $this;
  }

  public function setAnchor($anchor) {
    $this->anchor = $anchor;
    return $this;
  }

  public function setTitle($title) {
    $this->title = $title;
    return $this;
  }

  public function addClass($class) {
    $this->classes[] = $class;
    return $this;
  }

  public function setIcon($icon) {
    $this->icon = $icon;
    return $this;
  }

  public function setColor($color) {
    $this->color = $color;
    return $this;
  }

  public function getEventGroup() {
    return array_merge(array($this), $this->eventGroup);
  }

  public function addEventToGroup(PhabricatorTimelineEventView $event) {
    $this->eventGroup[] = $event;
    return $this;
  }


  public function renderEventTitle() {
    $title = $this->title;
    if (($title === null) && !$this->hasChildren()) {
      $title = '';
    }

    $extra = $this->renderExtra();

    if ($title !== null || $extra !== null) {
      $title_classes = array();
      $title_classes[] = 'phabricator-timeline-title';

      $icon = null;
      if ($this->icon) {
        $title_classes[] = 'phabricator-timeline-title-with-icon';

        $fill_classes = array();
        $fill_classes[] = 'phabricator-timeline-icon-fill';
        if ($this->color) {
          $fill_classes[] = 'phabricator-timeline-icon-fill-'.$this->color;
        }

        $icon = phutil_tag(
          'span',
          array(
            'class' => implode(' ', $fill_classes),
          ),
          phutil_tag(
            'span',
            array(
              'class' => 'phabricator-timeline-icon sprite-icons '.
                         'icons-'.$this->icon.'-white',
            ),
            ''));
      }

      $title = phutil_tag(
        'div',
        array(
          'class' => implode(' ', $title_classes),
        ),
        array($icon, $title, $extra));
    }

    return $title;
  }

  public function render() {

    $group_titles = array();
    $group_children = array();
    foreach ($this->getEventGroup() as $event) {
      $group_titles[] = $event->renderEventTitle();
      if ($event->hasChildren()) {
        $group_children[] = $event->renderChildren();
      }
    }

    $wedge = phutil_tag(
      'div',
      array(
        'class' => 'phabricator-timeline-wedge phabricator-timeline-border',
      ),
      '');

    $image_uri = $this->userHandle->getImageURI();
    $image = phutil_tag(
      'div',
      array(
        'style' => 'background-image: url('.$image_uri.')',
        'class' => 'phabricator-timeline-image',
      ),
      '');

    $content_classes = array();
    $content_classes[] = 'phabricator-timeline-content';

    $classes = array();
    $classes[] = 'phabricator-timeline-event-view';
    if ($group_children) {
      $classes[] = 'phabricator-timeline-major-event';
      $content = phutil_tag(
        'div',
        array(
          'class' => 'phabricator-timeline-inner-content',
        ),
        array(
          $group_titles,
          phutil_tag(
            'div',
            array(
              'class' => 'phabricator-timeline-core-content',
            ),
            $group_children),
        ));
    } else {
      $classes[] = 'phabricator-timeline-minor-event';
      $content = $group_titles;
    }

    $content = phutil_tag(
      'div',
      array(
        'class' => 'phabricator-timeline-group phabricator-timeline-border',
      ),
      $content);

    $content = phutil_tag(
      'div',
      array(
        'class' => implode(' ', $content_classes),
      ),
      array($image, $wedge, $content));

    $outer_classes = $this->classes;
    $outer_classes[] = 'phabricator-timeline-shell';
    $color = null;
    foreach ($this->getEventGroup() as $event) {
      if ($event->color) {
        $color = $event->color;
        break;
      }
    }

    if ($color) {
      $outer_classes[] = 'phabricator-timeline-'.$color;
    }

    $sigil = null;
    $meta = null;
    if ($this->getTransactionPHID()) {
      $sigil = 'transaction';
      $meta = array(
        'phid' => $this->getTransactionPHID(),
        'anchor' => $this->anchor,
      );
    }

    return javelin_tag(
      'div',
      array(
        'class' => implode(' ', $outer_classes),
        'id' => $this->anchor ? 'anchor-'.$this->anchor : null,
        'sigil' => $sigil,
        'meta' => $meta,
      ),
      phutil_tag(
        'div',
        array(
          'class' => implode(' ', $classes),
        ),
        $content));
  }

  private function renderExtra() {
    $extra = array();

    if ($this->getIsPreview()) {
      $extra[] = pht('PREVIEW');
    } else {
      $xaction_phid = $this->getTransactionPHID();


      if ($this->getIsEdited()) {
        $extra[] = javelin_tag(
          'a',
          array(
            'href'  => '/transactions/history/'.$xaction_phid.'/',
            'sigil' => 'workflow',
          ),
          pht('Edited'));
      }

      if ($this->getIsEditable()) {
        $extra[] = javelin_tag(
          'a',
          array(
            'href'  => '/transactions/edit/'.$xaction_phid.'/',
            'sigil' => 'workflow transaction-edit',
          ),
          pht('Edit'));
      }

      $source = $this->getContentSource();
      if ($source) {
        $extra[] = id(new PhabricatorContentSourceView())
          ->setContentSource($source)
          ->setUser($this->getUser())
          ->render();
      }

      if ($this->getDateCreated()) {
        $date = phabricator_datetime(
          $this->getDateCreated(),
          $this->getUser());
        if ($this->anchor) {
          Javelin::initBehavior('phabricator-watch-anchor');

          $anchor = id(new PhabricatorAnchorView())
            ->setAnchorName($this->anchor)
            ->render();

          $date = array(
            $anchor,
            phutil_tag(
              'a',
              array(
                'href' => '#'.$this->anchor,
              ),
              $date),
          );
        }
        $extra[] = $date;
      }
    }

    if ($extra) {
      $extra = phutil_tag(
        'span',
        array(
          'class' => 'phabricator-timeline-extra',
        ),
        phutil_implode_html(" \xC2\xB7 ", $extra));
    }

    return $extra;
  }

}
