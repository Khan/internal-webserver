<?php

/*
 * Copyright 2011 Facebook, Inc.
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

/**
 * @group maniphest
 */
final class ManiphestTaskSubscriber extends ManiphestDAO {

  protected $taskPHID;
  protected $subscriberPHID;

  public function getConfiguration() {
    return array(
      self::CONFIG_IDS          => self::IDS_MANUAL,
      self::CONFIG_TIMESTAMPS   => false,
    );
  }

  public static function updateTaskSubscribers(ManiphestTask $task) {
    $dao = new ManiphestTaskSubscriber();
    $conn = $dao->establishConnection('w');

    $sql = array();
    $subscribers = $task->getCCPHIDs();
    $subscribers[] = $task->getOwnerPHID();
    $subscribers = array_unique($subscribers);

    foreach ($subscribers as $subscriber_phid) {
      $sql[] = qsprintf(
        $conn,
        '(%s, %s)',
        $task->getPHID(),
        $subscriber_phid);
    }

    queryfx(
      $conn,
      'DELETE FROM %T WHERE taskPHID = %s',
      $dao->getTableName(),
      $task->getPHID());
    if ($sql) {
      queryfx(
        $conn,
        'INSERT INTO %T (taskPHID, subscriberPHID) VALUES %Q',
        $dao->getTableName(),
        implode(', ', $sql));
    }
  }

}
