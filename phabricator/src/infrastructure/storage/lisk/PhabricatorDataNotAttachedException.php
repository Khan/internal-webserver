<?php

final class PhabricatorDataNotAttachedException extends Exception {

  public function __construct(PhabricatorLiskDAO $dao) {
    $stack = debug_backtrace();

    // Shift off `PhabricatorDataNotAttachedException::__construct()`.
    array_shift($stack);
    // Shift off `PhabricatorLiskDAO::assertAttached()`.
    array_shift($stack);

    $frame = head($stack);
    $via = null;
    if (is_array($frame)) {
      $method = idx($frame, 'function');
      if (preg_match('/^get[A-Z]/', $method)) {
        $via = " (via {$method}())";
      }
    }

    $class = get_class($dao);

    $message =
      "Attempting to access attached data on {$class}{$via}, but the data is ".
      "not actually attached. Before accessing attachable data on an object, ".
      "you must load and attach it.\n\n".
      "Data is normally attached by calling the corresponding needX() ".
      "method on the Query class when the object is loaded. You can also ".
      "call the corresponding attachX() method explicitly.";

    parent::__construct($message);
  }

}
