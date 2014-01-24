<?php

final class PhabricatorNotificationBuilder {

  private $stories;
  private $user = null;

  public function __construct(array $stories) {
    $this->stories = $stories;
  }

  public function setUser($user) {
    $this->user = $user;
    return $this;
  }

  public function buildView() {

    $stories = $this->stories;
    $stories = mpull($stories, null, 'getChronologicalKey');

    // Aggregate notifications. Generally, we can aggregate notifications only
    // by object, e.g. "a updated T123" and "b updated T123" can become
    // "a and b updated T123", but we can't combine "a updated T123" and
    // "a updated T234" into "a updated T123 and T234" because there would be
    // nowhere sensible for the notification to link to, and no reasonable way
    // to unambiguously clear it.

    // Each notification emits keys it can aggregate on. For instance, if this
    // story is "a updated T123", it might emit a key like this:
    //
    //   task:phid123:unread => PhabricatorFeedStoryManiphestAggregate
    //
    // All the unread notifications about the task with PHID "phid123" will
    // emit the same key, telling us we can aggregate them into a single
    // story of type "PhabricatorFeedStoryManiphestAggregate", which could
    // read like "a and b updated T123".
    //
    // A story might be able to aggregate in multiple ways. Although this is
    // unlikely for stories in a notification context, stories in a feed context
    // can also aggregate by actor:
    //
    //   task:phid123   => PhabricatorFeedStoryManiphestAggregate
    //   actor:user123  => PhabricatorFeedStoryActorAggregate
    //
    // This means the story can either become "a and b updated T123" or
    // "a updated T123 and T456". When faced with multiple possibilities, it's
    // our job to choose the best aggregation.
    //
    // For now, we use a simple greedy algorithm and repeatedly select the
    // aggregate story which consumes the largest number of individual stories
    // until no aggregate story exists that consumes more than one story.


    // Build up a map of all the possible aggregations.

    $chronokey_map = array();
    $aggregation_map = array();
    $agg_types = array();
    foreach ($stories as $chronokey => $story) {
      $chronokey_map[$chronokey] = $story->getNotificationAggregations();
      foreach ($chronokey_map[$chronokey] as $key => $type) {
        $agg_types[$key] = $type;
        $aggregation_map[$key]['keys'][$chronokey] = true;
      }
    }

    // Repeatedly select the largest available aggregation until none remain.

    $aggregated_stories = array();
    while ($aggregation_map) {

      // Count the size of each aggregation, removing any which will consume
      // fewer than 2 stories.

      foreach ($aggregation_map as $key => $dict) {
        $size = count($dict['keys']);
        if ($size > 1) {
          $aggregation_map[$key]['size'] = $size;
        } else {
          unset($aggregation_map[$key]);
        }
      }

      // If we're out of aggregations, break out.

      if (!$aggregation_map) {
        break;
      }

      // Select the aggregation we're going to make, and remove it from the
      // map.

      $aggregation_map = isort($aggregation_map, 'size');
      $agg_info = idx(last($aggregation_map), 'keys');
      $agg_key  = last_key($aggregation_map);
      unset($aggregation_map[$agg_key]);

      // Select all the stories it aggregates, and remove them from the master
      // list of stories and from all other possible aggregations.

      $sub_stories = array();
      foreach ($agg_info as $chronokey => $ignored) {
        $sub_stories[$chronokey] = $stories[$chronokey];
        unset($stories[$chronokey]);
        foreach ($chronokey_map[$chronokey] as $key => $type) {
          unset($aggregation_map[$key]['keys'][$chronokey]);
        }
        unset($chronokey_map[$chronokey]);
      }

      // Build the aggregate story.

      krsort($sub_stories);
      $story_class = $agg_types[$agg_key];
      $conv = array(head($sub_stories)->getStoryData());

      $new_story = newv($story_class, $conv);
      $new_story->setAggregateStories($sub_stories);
      $aggregated_stories[] = $new_story;
    }

    // Combine the aggregate stories back into the list of stories.

    $stories = array_merge($stories, $aggregated_stories);
    $stories = mpull($stories, null, 'getChronologicalKey');
    krsort($stories);

    $null_view = new AphrontNullView();

    foreach ($stories as $story) {
      $view = $story->renderView();
      $null_view->appendChild($view->renderNotification($this->user));
    }

    return $null_view;
  }
}
