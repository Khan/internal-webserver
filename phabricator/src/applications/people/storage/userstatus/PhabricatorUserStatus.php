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

final class PhabricatorUserStatus extends PhabricatorUserDAO {

  const STATUS_AWAY = 1;
  const STATUS_SPORADIC = 2;

  private static $statusTexts = array(
    self::STATUS_AWAY => 'away',
    self::STATUS_SPORADIC => 'sporadic',
  );

  protected $userPHID;
  protected $dateFrom;
  protected $dateTo;
  protected $status;

  public function getTextStatus() {
    return self::$statusTexts[$this->status];
  }

  public function getStatusDescription(PhabricatorUser $viewer) {
    $until = phabricator_date($this->dateTo, $viewer);
    if ($this->status == PhabricatorUserStatus::STATUS_SPORADIC) {
      return 'Sporadic until '.$until;
    } else {
      return 'Away until '.$until;
    }
  }

  public function setTextStatus($status) {
    $statuses = array_flip(self::$statusTexts);
    return $this->setStatus($statuses[$status]);
  }

  public function loadCurrentStatuses($user_phids) {
    $statuses = $this->loadAllWhere(
      'userPHID IN (%Ls) AND UNIX_TIMESTAMP() BETWEEN dateFrom AND dateTo',
      $user_phids);
    return mpull($statuses, null, 'getUserPHID');
  }

}
