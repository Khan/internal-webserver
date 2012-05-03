#!/usr/bin/env php
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

$root = dirname(dirname(dirname(__FILE__)));
require_once $root.'/scripts/__init_script__.php';

// http://www.opm.gov/operating_status_schedules/fedhol/
$holidays = array(
  '2012-01-02' => "New Year's Day",
  '2012-01-16' => "Birthday of Martin Luther King, Jr.",
  '2012-02-20' => "Washington's Birthday",
  '2012-05-28' => "Memorial Day",
  '2012-07-04' => "Independence Day",
  '2012-09-03' => "Labor Day",
  '2012-10-08' => "Columbus Day",
  '2012-11-12' => "Veterans Day",
  '2012-11-22' => "Thanksgiving Day",
  '2012-12-25' => "Christmas Day",
  '2013-01-01' => "New Year's Day",
  '2013-01-21' => "Birthday of Martin Luther King, Jr.",
  '2013-02-18' => "Washington's Birthday",
  '2013-05-27' => "Memorial Day",
  '2013-07-04' => "Independence Day",
  '2013-09-02' => "Labor Day",
  '2013-10-14' => "Columbus Day",
  '2013-11-11' => "Veterans Day",
  '2013-11-28' => "Thanksgiving Day",
  '2013-12-25' => "Christmas Day",
  '2014-01-01' => "New Year's Day",
  '2014-01-20' => "Birthday of Martin Luther King, Jr.",
  '2014-02-17' => "Washington's Birthday",
  '2014-05-26' => "Memorial Day",
  '2014-07-04' => "Independence Day",
  '2014-09-01' => "Labor Day",
  '2014-10-13' => "Columbus Day",
  '2014-11-11' => "Veterans Day",
  '2014-11-27' => "Thanksgiving Day",
  '2014-12-25' => "Christmas Day",
  '2015-01-01' => "New Year's Day",
  '2015-01-19' => "Birthday of Martin Luther King, Jr.",
  '2015-02-16' => "Washington's Birthday",
  '2015-05-25' => "Memorial Day",
  '2015-07-03' => "Independence Day",
  '2015-09-07' => "Labor Day",
  '2015-10-12' => "Columbus Day",
  '2015-11-11' => "Veterans Day",
  '2015-11-26' => "Thanksgiving Day",
  '2015-12-25' => "Christmas Day",
);

$table = new PhabricatorCalendarHoliday();
$conn_w = $table->establishConnection('w');
$table_name = $table->getTableName();

foreach ($holidays as $day => $name) {
  queryfx(
    $conn_w,
    'INSERT IGNORE INTO %T (day, name) VALUES (%s, %s)',
    $table_name,
    $day,
    $name);
}
