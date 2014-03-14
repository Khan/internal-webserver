<?php

final class PHUITimelineView extends AphrontView {

  private $events = array();
  private $id;
  private $shouldTerminate = false;

  public function setID($id) {
    $this->id = $id;
    return $this;
  }

  public function setShouldTerminate($term) {
    $this->shouldTerminate = $term;
    return $this;
  }

  public function addEvent(PHUITimelineEventView $event) {
    $this->events[] = $event;
    return $this;
  }

  public function render() {
    require_celerity_resource('phui-timeline-view-css');

    $spacer = self::renderSpacer();

    $hide = array();
    $show = array();

    foreach ($this->events as $event) {
      if ($event->getHideByDefault()) {
        $hide[] = $event;
      } else {
        $show[] = $event;
      }
    }

    $events = array();
    if ($hide) {
      $hidden = phutil_implode_html($spacer, $hide);
      $count = count($hide);

      $show_id = celerity_generate_unique_node_id();
      $hide_id = celerity_generate_unique_node_id();
      $link_id = celerity_generate_unique_node_id();

      Javelin::initBehavior(
        'phabricator-show-all-transactions',
        array(
          'anchors' => array_filter(mpull($hide, 'getAnchor')),
          'linkID' => $link_id,
          'hideID' => $hide_id,
          'showID' => $show_id,
        ));

      $events[] = phutil_tag(
        'div',
        array(
          'id' => $hide_id,
          'class' => 'phui-timeline-older-transactions-are-hidden',
        ),
        array(
          pht('%s older changes(s) are hidden.', new PhutilNumber($count)),
          ' ',
          javelin_tag(
            'a',
            array(
              'href' => '#',
              'mustcapture' => true,
              'id' => $link_id,
            ),
            pht('Show all changes.')),
        ));

      $events[] = phutil_tag(
        'div',
        array(
          'id' => $show_id,
          'style' => 'display: none',
        ),
        $hidden);
    }

    if ($hide && $show) {
      $events[] = $spacer;
    }

    if ($show) {
      $events[] = phutil_implode_html($spacer, $show);
    }

    if ($events) {
      $events = array($spacer, $events, $spacer);
    } else {
      $events = array($spacer);
    }

    if ($this->shouldTerminate) {
      $events[] = self::renderEnder(true);
    }

    return phutil_tag(
      'div',
      array(
        'class' => 'phui-timeline-view',
        'id' => $this->id,
      ),
      $events);
  }

  public static function renderSpacer() {
    return phutil_tag(
      'div',
      array(
        'class' => 'phui-timeline-event-view '.
                   'phui-timeline-spacer',
      ),
      '');
  }

  public static function renderEnder() {
    return phutil_tag(
      'div',
      array(
        'class' => 'phui-timeline-event-view '.
                   'the-worlds-end',
      ),
      '');
  }

}
