<?php

final class PhabricatorMenuItemView extends AphrontTagView {

  const TYPE_LINK     = 'type-link';
  const TYPE_SPACER   = 'type-spacer';
  const TYPE_LABEL    = 'type-label';
  const TYPE_BUTTON   = 'type-button';

  private $name;
  private $href;
  private $type = self::TYPE_LINK;
  private $isExternal;
  private $key;
  private $sortOrder = 1.0;
  private $icon;
  private $selected;

  public function setProperty($property) {
    $this->property = $property;
    return $this;
  }

  public function getProperty() {
    return $this->property;
  }

  public function setSelected($selected) {
    $this->selected = $selected;
    return $this;
  }

  public function getSelected() {
    return $this->selected;
  }

  public function setIcon($icon) {
    $this->icon = $icon;
    return $this;
  }

  public function getIcon() {
    return $this->icon;
  }

  public function setKey($key) {
    $this->key = (string)$key;
    return $this;
  }

  public function getKey() {
    return $this->key;
  }

  public function setType($type) {
    $this->type = $type;
    return $this;
  }

  public function getType() {
    return $this->type;
  }

  public function setHref($href) {
    $this->href = $href;
    return $this;
  }

  public function getHref() {
    return $this->href;
  }

  public function setName($name) {
    $this->name = $name;
    return $this;
  }

  public function getName() {
    return $this->name;
  }

  public function setIsExternal($is_external) {
    $this->isExternal = $is_external;
    return $this;
  }

  public function getIsExternal() {
    return $this->isExternal;
  }

  public function setSortOrder($order) {
    $this->sortOrder = $order;
    return $this;
  }

  public function getSortOrder() {
    return $this->sortOrder;
  }

  protected function getTagName() {
    return $this->href ? 'a' : 'div';
  }

  protected function getTagAttributes() {
    return array(
      'class' => array(
        'phabricator-menu-item-view',
        'phabricator-menu-item-'.$this->type,
      ),
      'href' => $this->href,
    );
  }

  protected function getTagContent() {
    $name = null;
    if ($this->name) {
      $external = null;
      if ($this->isExternal) {
        $external = " \xE2\x86\x97";
      }
      $name = phutil_render_tag(
        'span',
        array(
          'class' => 'phabricator-menu-item-name',
        ),
        phutil_escape_html($this->name.$external));
    }

    return $this->renderChildren().$name;
  }

}
