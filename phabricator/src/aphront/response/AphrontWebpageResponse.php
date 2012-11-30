<?php

/**
 * @group aphront
 */
final class AphrontWebpageResponse extends AphrontHTMLResponse {

  private $content;

  public function setContent($content) {
    $this->content = $content;
    return $this;
  }

  public function buildResponseString() {
    return $this->content;
  }

}
