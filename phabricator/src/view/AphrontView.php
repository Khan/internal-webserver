<?php

abstract class AphrontView extends Phobject {

  protected $user;
  protected $children = array();

  public function setUser(PhabricatorUser $user) {
    $this->user = $user;
    return $this;
  }

  protected function getUser() {
    return $this->user;
  }

  protected function canAppendChild() {
    return true;
  }

  final public function appendChild($child) {
    if (!$this->canAppendChild()) {
      $class = get_class($this);
      throw new Exception(
        pht("View '%s' does not support children.", $class));
    }
    $this->children[] = $child;
    return $this;
  }

  final protected function renderChildren() {
    $out = array();
    foreach ($this->children as $child) {
      $out[] = $this->renderSingleView($child);
    }
    return $out;
  }

  final protected function renderSingleView($child) {
    if ($child instanceof AphrontView) {
      return $child->render();
    } else if (is_array($child)) {
      $out = array();
      foreach ($child as $element) {
        $out[] = $this->renderSingleView($element);
      }
      return phutil_implode_html('', $out);
    } else {
      return $child;
    }
  }

  final protected function isEmptyContent($content) {
    if (is_array($content)) {
      foreach ($content as $element) {
        if (!$this->isEmptyContent($element)) {
          return false;
        }
      }
      return true;
    } else {
      return !strlen((string)$content);
    }
  }

  abstract public function render();

}
