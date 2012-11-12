<?php

final class PhabricatorHandleObjectSelectorDataView {

  private $handle;

  public function __construct($handle) {
    $this->handle = $handle;
  }

  public function renderData() {
    $handle = $this->handle;
    return array(
      'phid' => $handle->getPHID(),
      'name' => $handle->getFullName(),
      'uri'  => $handle->getURI(),
    );
  }
}
