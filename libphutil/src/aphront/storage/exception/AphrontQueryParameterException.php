<?php

/**
 * @group storage
 */
final class AphrontQueryParameterException extends AphrontQueryException {

  private $query;

  public function __construct($query, $message) {
    parent::__construct($message." Query: ".$query);
    $this->query = $query;
  }

  public function getQuery() {
    return $this->query;
  }

}
