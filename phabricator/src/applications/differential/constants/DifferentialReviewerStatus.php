<?php

final class DifferentialReviewerStatus {

  const STATUS_BLOCKING = 'blocking';
  const STATUS_ADDED = 'added';
  const STATUS_ACCEPTED = 'accepted';
  const STATUS_REJECTED = 'rejected';
  const STATUS_COMMENTED = 'commented';

  /**
   * Returns the relative strength of a status, used to pick a winner when a
   * transaction group makes several status changes to a particular reviewer.
   *
   * For example, if you accept a revision and leave a comment, the transactions
   * will attempt to update you to both "commented" and "accepted". We want
   * "accepted" to win, because it's the stronger of the two.
   *
   * @param   const Reviewer status constant.
   * @return  int   Relative strength (higher is stronger).
   */
  public static function getStatusStrength($constant) {
    $map = array(
      self::STATUS_ADDED      => 1,

      self::STATUS_COMMENTED  => 2,

      self::STATUS_BLOCKING   => 3,

      self::STATUS_ACCEPTED   => 4,
      self::STATUS_REJECTED   => 4,
    );

    return idx($map, $constant, 0);
  }

}
