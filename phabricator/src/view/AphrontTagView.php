<?php

/**
 * View which renders down to a single tag, and provides common access for tag
 * attributes (setting classes, sigils, IDs, etc).
 */
abstract class AphrontTagView extends AphrontView {

  private $id;
  private $classes = array();
  private $sigils = array();
  private $style;
  private $metadata;
  private $mustCapture;
  private $workflow;


  public function setWorkflow($workflow) {
    $this->workflow = $workflow;
    return $this;
  }

  public function getWorkflow() {
    return $this->workflow;
  }

  public function setMustCapture($must_capture) {
    $this->mustCapture = $must_capture;
    return $this;
  }

  public function getMustCapture() {
    return $this->mustCapture;
  }

  final public function setMetadata(array $metadata) {
    $this->metadata = $metadata;
    return $this;
  }

  final public function getMetadata() {
    return $this->metadata;
  }

  final public function setStyle($style) {
    $this->style = $style;
    return $this;
  }

  final public function getStyle() {
    return $this->style;
  }

  final public function addSigil($sigil) {
    $this->sigils[] = $sigil;
    return $this;
  }

  final public function getSigils() {
    return $this->sigils;
  }

  public function addClass($class) {
    $this->classes[] = $class;
    return $this;
  }

  public function getClass() {
    return $this->class;
  }

  public function setID($id) {
    $this->id = $id;
    return $this;
  }

  public function getID() {
    return $this->id;
  }

  protected function getTagName() {
    return 'div';
  }

  protected function getTagAttributes() {
    return array();
  }

  protected function getTagContent() {
    return $this->renderHTMLChildren();
  }

  protected function willRender() {
    return;
  }

  final public function render() {
    $this->willRender();

    $attributes = $this->getTagAttributes();

    $implode = array('class', 'sigil');
    foreach ($implode as $attr) {
      if (isset($attributes[$attr])) {
        if (is_array($attributes[$attr])) {
          $attributes[$attr] = implode(' ', $attributes[$attr]);
        }
      }
    }

    if (!is_array($attributes)) {
      $class = get_class($this);
      throw new Exception(
        "View '{$class}' did not return an array from getTagAttributes()!");
    }

    $sigils = $this->sigils;
    if ($this->workflow) {
      $sigils[] = 'workflow';
    }

    $tag_view_attributes = array(
      'id' => $this->id,

      'class' => $this->classes ? implode(' ', $this->classes) : null,
      'style' => $this->style,

      'meta' => $this->metadata,
      'sigil' => $sigils ? implode(' ', $sigils) : null,
      'mustcapture' => $this->mustCapture,
    );

    foreach ($tag_view_attributes as $key => $value) {
      if ($value === null) {
        continue;
      }
      if (!isset($attributes[$key])) {
        $attributes[$key] = $value;
        continue;
      }
      switch ($key) {
        case 'class':
        case 'sigil':
          $attributes[$key] = $attributes[$key].' '.$value;
          break;
        default:
          // Use the explicitly set value rather than the tag default value.
          $attributes[$key] = $value;
          break;
      }
    }

    return javelin_tag(
      $this->getTagName(),
      $attributes,
      $this->getTagContent());
  }
}
