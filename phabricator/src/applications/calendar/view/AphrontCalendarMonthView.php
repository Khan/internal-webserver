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

final class AphrontCalendarMonthView extends AphrontView {

  private $user;
  private $month;
  private $year;
  private $holidays = array();
  private $events   = array();

  public function setUser(PhabricatorUser $user) {
    $this->user = $user;
    return $this;
  }

  public function addEvent(AphrontCalendarEventView $event) {
    $this->events[] = $event;
    return $this;
  }

  public function setHolidays(array $holidays) {
    assert_instances_of($holidays, 'PhabricatorCalendarHoliday');
    $this->holidays = mpull($holidays, null, 'getDay');
    return $this;
  }

  public function __construct($month, $year) {
    $this->month = $month;
    $this->year = $year;
  }

  public function render() {
    if (empty($this->user)) {
      throw new Exception("Call setUser() before render()!");
    }

    $events = msort($this->events, 'getEpochStart');

    $days = $this->getDatesInMonth();

    require_celerity_resource('aphront-calendar-view-css');

    $first = reset($days);
    $empty = $first->format('w');

    $markup = array();

    $empty_box =
      '<div class="aphront-calendar-day aphront-calendar-empty">'.
      '</div>';

    for ($ii = 0; $ii < $empty; $ii++) {
      $markup[] = $empty_box;
    }

    $show_events = array();

    foreach ($days as $day) {
      $holiday = idx($this->holidays, $day->format('Y-m-d'));
      $class = 'aphront-calendar-day';
      $weekday = $day->format('w');
      if ($holiday || $weekday == 0 || $weekday == 6) {
        $class .= ' aphront-calendar-not-work-day';
      }

      $day->setTime(0, 0, 0);
      $epoch_start = $day->format('U');

      $day->setTime(24, 0, 0);
      $epoch_end = $day->format('U');

      if ($weekday == 0) {
        $show_events = array();
      } else {
        $show_events = array_fill_keys(
          array_keys($show_events),
          '<div class="aphront-calendar-event aphront-calendar-event-empty">'.
            '&nbsp;'.
          '</div>');
      }

      foreach ($events as $event) {
        if ($event->getEpochStart() >= $epoch_end) {
          // This list is sorted, so we can stop looking.
          break;
        }
        if ($event->getEpochStart() < $epoch_end &&
            $event->getEpochEnd() > $epoch_start) {
          $show_events[$event->getUserPHID()] = $this->renderEvent(
            $event,
            $day,
            $epoch_start,
            $epoch_end);
        }
      }

      $holiday_markup = null;
      if ($holiday) {
        $name = phutil_escape_html($holiday->getName());
        $holiday_markup =
          '<div class="aphront-calendar-holiday" title="'.$name.'">'.
            $name.
          '</div>';
      }

      $markup[] =
        '<div class="'.$class.'">'.
          '<div class="aphront-calendar-date-number">'.
            $day->format('j').
          '</div>'.
          $holiday_markup.
          implode("\n", $show_events).
        '</div>';
    }

    $table = array();
    $rows = array_chunk($markup, 7);
    foreach ($rows as $row) {
      $table[] = '<tr>';
      while (count($row) < 7) {
        $row[] = $empty_box;
      }
      foreach ($row as $cell) {
        $table[] = '<td>'.$cell.'</td>';
      }
      $table[] = '</tr>';
    }
    $table =
      '<table class="aphront-calendar-view">'.
        '<tr class="aphront-calendar-month-year-header">'.
          '<th colspan="7">'.$first->format('F Y').'</th>'.
        '</tr>'.
        '<tr class="aphront-calendar-day-of-week-header">'.
          '<th>Sun</th>'.
          '<th>Mon</th>'.
          '<th>Tue</th>'.
          '<th>Wed</th>'.
          '<th>Thu</th>'.
          '<th>Fri</th>'.
          '<th>Sat</th>'.
        '</tr>'.
        implode("\n", $table).
      '</table>';

    return $table;
  }

  /**
   * Return a DateTime object representing the first moment in each day in the
   * month, according to the user's locale.
   *
   * @return list List of DateTimes, one for each day.
   */
  private function getDatesInMonth() {
    $user = $this->user;

    $timezone = new DateTimeZone($user->getTimezoneIdentifier());

    $month = $this->month;
    $year = $this->year;

    // Find the year and month numbers of the following month, so we can
    // determine when this month ends.
    $next_year = $year;
    $next_month = $month + 1;
    if ($next_month == 13) {
      $next_year = $year + 1;
      $next_month = 1;
    }

    $end_date = new DateTime("{$next_year}-{$next_month}-01", $timezone);
    $end_epoch = $end_date->format('U');

    $days = array();
    for ($day = 1; $day <= 31; $day++) {
      $day_date = new DateTime("{$year}-{$month}-{$day}", $timezone);
      $day_epoch = $day_date->format('U');
      if ($day_epoch >= $end_epoch) {
        break;
      } else {
        $days[] = $day_date;
      }
    }

    return $days;
  }

  private function renderEvent(
    AphrontCalendarEventView $event,
    DateTime $day,
    $epoch_start,
    $epoch_end) {

    $user = $this->user;

    $event_start = $event->getEpochStart();
    $event_end   = $event->getEpochEnd();

    $classes = array();
    $when = array();

    $classes[] = 'aphront-calendar-event';
    if ($event_start < $epoch_start) {
      $classes[] = 'aphront-calendar-event-continues-before';
      $when[] = 'Started '.phabricator_datetime($event_start, $user);
    } else {
      $when[] = 'Starts at '.phabricator_time($event_start, $user);
    }

    if ($event_end > $epoch_end) {
      $classes[] = 'aphront-calendar-event-continues-after';
      $when[] = 'Ends '.phabricator_datetime($event_end, $user);
    } else {
      $when[] = 'Ends at '.phabricator_time($event_end, $user);
    }

    Javelin::initBehavior('phabricator-tooltips');

    $info = $event->getName();
    if ($event->getDescription()) {
      $info .= "\n\n".$event->getDescription();
    }

    $text_div = javelin_render_tag(
      'div',
      array(
        'sigil' => 'has-tooltip',
        'meta'  => array(
          'tip'   => $info."\n\n".implode("\n", $when),
          'size'  => 240,
        ),
        'class' => 'aphront-calendar-event-text',
      ),
      phutil_escape_html(phutil_utf8_shorten($event->getName(), 32)));

    return javelin_render_tag(
      'div',
      array(
        'class' => implode(' ', $classes),
      ),
      $text_div);
  }

}
