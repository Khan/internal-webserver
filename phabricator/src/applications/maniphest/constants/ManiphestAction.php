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

/**
 * @group maniphest
 */
final class ManiphestAction extends PhrictionConstants {
  /* These actions must be determined when the story
     is generated and thus are new */
  const ACTION_CREATE      = 'create';
  const ACTION_REOPEN      = 'reopen';
  const ACTION_CLOSE       = 'close';
  const ACTION_UPDATE      = 'update';
  const ACTION_ASSIGN      = 'assign';

  /* these actions are determined sufficiently by the transaction
     type and thus we use them here*/
  const ACTION_COMMENT     = ManiphestTransactionType::TYPE_NONE;
  const ACTION_CC          = ManiphestTransactionType::TYPE_CCS;
  const ACTION_PRIORITY    = ManiphestTransactionType::TYPE_PRIORITY;
  const ACTION_PROJECT     = ManiphestTransactionType::TYPE_PROJECTS;
  const ACTION_TITLE       = ManiphestTransactionType::TYPE_TITLE;
  const ACTION_DESCRIPTION = ManiphestTransactionType::TYPE_DESCRIPTION;
  const ACTION_REASSIGN    = ManiphestTransactionType::TYPE_OWNER;
  const ACTION_ATTACH      = ManiphestTransactionType::TYPE_ATTACH;

  public static function getActionPastTenseVerb($action) {
    static $map = array(
      self::ACTION_CREATE      => 'created',
      self::ACTION_CLOSE       => 'closed',
      self::ACTION_UPDATE      => 'updated',
      self::ACTION_ASSIGN      => 'assigned',
      self::ACTION_REASSIGN      => 'reassigned',
      self::ACTION_COMMENT     => 'commented on',
      self::ACTION_CC          => 'updated cc\'s of',
      self::ACTION_PRIORITY    => 'changed the priority of',
      self::ACTION_PROJECT     => 'modified projects of',
      self::ACTION_TITLE       => 'updated title of',
      self::ACTION_DESCRIPTION => 'updated description of',
      self::ACTION_ATTACH      => 'attached something to',
      self::ACTION_REOPEN      => 'reopened',
    );

    return idx($map, $action, "brazenly {$action}'d");
  }

  /**
   * If a group of transactions contain several actions, select the "strongest"
   * action. For instance, a close is stronger than an update, because we want
   * to render "User U closed task T" instead of "User U updated task T" when
   * a user closes a task.
   */
  public static function selectStrongestAction(array $actions) {
    static $strengths = array(
      self::ACTION_UPDATE      => 0,
      self::ACTION_CC          => 1,
      self::ACTION_PROJECT     => 2,
      self::ACTION_DESCRIPTION => 3,
      self::ACTION_TITLE       => 4,
      self::ACTION_ATTACH      => 5,
      self::ACTION_COMMENT     => 6,
      self::ACTION_PRIORITY    => 7,
      self::ACTION_REASSIGN    => 8,
      self::ACTION_ASSIGN      => 9,
      self::ACTION_REOPEN      => 10,
      self::ACTION_CREATE      => 11,
      self::ACTION_CLOSE       => 12,
    );

    $strongest = null;
    $strength = -1;
    foreach ($actions as $action) {
      if ($strengths[$action] > $strength) {
        $strength = $strengths[$action];
        $strongest = $action;
      }
    }
    return $strongest;
  }

}
