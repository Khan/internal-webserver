<?php

/**
 * Iterate over every object of a given type, without holding all of them in
 * memory. This is useful for performing database migrations.
 *
 *   $things = new LiskMigrationIterator(new LiskThing());
 *   foreach ($things as $thing) {
 *     // do something
 *   }
 *
 * NOTE: This only works on objects with a normal `id` column.
 *
 * @task storage
 */
final class LiskMigrationIterator extends PhutilBufferedIterator {

  private $object;
  private $cursor;
  private $set;

  public function __construct(LiskDAO $object) {
    $this->set = new LiskDAOSet();
    $this->object = $object->putInSet($this->set);
  }

  protected function didRewind() {
    $this->cursor = 0;
  }

  public function key() {
    return $this->current()->getID();
  }

  protected function loadPage() {
    $this->set->clearSet();

    $results = $this->object->loadAllWhere(
      'id > %d ORDER BY id ASC LIMIT %d',
      $this->cursor,
      $this->getPageSize());
    if ($results) {
      $this->cursor = last($results)->getID();
    }
    return $results;
  }

}
