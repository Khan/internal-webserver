<?php

final class PHUITagView extends AphrontView {

  const TYPE_PERSON         = 'person';
  const TYPE_OBJECT         = 'object';
  const TYPE_STATE          = 'state';

  const COLOR_RED           = 'red';
  const COLOR_ORANGE        = 'orange';
  const COLOR_YELLOW        = 'yellow';
  const COLOR_BLUE          = 'blue';
  const COLOR_INDIGO        = 'indigo';
  const COLOR_VIOLET        = 'violet';
  const COLOR_GREEN         = 'green';
  const COLOR_BLACK         = 'black';
  const COLOR_GREY          = 'grey';
  const COLOR_WHITE         = 'white';

  const COLOR_OBJECT        = 'object';
  const COLOR_PERSON        = 'person';

  private $type;
  private $href;
  private $name;
  private $phid;
  private $backgroundColor;
  private $dotColor;
  private $closed;
  private $external;
  private $id;
  private $icon;

  public function setID($id) {
    $this->id = $id;
    return $this;
  }

  public function getID() {
    return $this->id;
  }

  public function setType($type) {
    $this->type = $type;
    switch ($type) {
      case self::TYPE_OBJECT:
        $this->setBackgroundColor(self::COLOR_OBJECT);
        break;
      case self::TYPE_PERSON:
        $this->setBackgroundColor(self::COLOR_PERSON);
        break;
    }
    return $this;
  }

  public function setDotColor($dot_color) {
    $this->dotColor = $dot_color;
    return $this;
  }

  public function setBackgroundColor($background_color) {
    $this->backgroundColor = $background_color;
    return $this;
  }

  public function setPHID($phid) {
    $this->phid = $phid;
    return $this;
  }

  public function setName($name) {
    $this->name = $name;
    return $this;
  }

  public function setHref($href) {
    $this->href = $href;
    return $this;
  }

  public function setClosed($closed) {
    $this->closed = $closed;
    return $this;
  }

  public function setIcon($icon) {
    $icon_view = id(new PHUIIconView())
      ->setSpriteSheet(PHUIIconView::SPRITE_ICONS)
      ->setSpriteIcon($icon);
    $this->icon = $icon_view;
    return $this;
  }

  public function render() {
    if (!$this->type) {
      throw new Exception(pht("You must call setType() before render()!"));
    }

    require_celerity_resource('phui-tag-view-css');
    $classes = array(
      'phui-tag-view',
      'phui-tag-type-'.$this->type,
    );

    if ($this->closed) {
      $classes[] = 'phui-tag-state-closed';
    }

    $color = null;
    if ($this->backgroundColor) {
      $color = 'phui-tag-color-'.$this->backgroundColor;
    }

    if ($this->dotColor) {
      $dotcolor = 'phui-tag-color-'.$this->dotColor;
      $dot = phutil_tag(
        'span',
        array(
          'class' => 'phui-tag-dot '.$dotcolor,
        ),
        '');
    } else {
      $dot = null;
    }

    if ($this->icon) {
      $icon = $this->icon;
      $classes[] = 'phui-tag-icon-view';
    } else {
      $icon = null;
    }

    $content = phutil_tag(
      'span',
      array(
        'class' => 'phui-tag-core '.$color,
      ),
      array($dot, $this->name));

    if ($this->phid) {
      Javelin::initBehavior('phabricator-hovercards');

      return javelin_tag(
        'a',
        array(
          'id' => $this->id,
          'href'  => $this->href,
          'class' => implode(' ', $classes),
          'sigil' => 'hovercard',
          'meta'  => array(
            'hoverPHID' => $this->phid,
          ),
          'target' => $this->external ? '_blank' : null,
        ),
        array($icon, $content));
    } else {
      return phutil_tag(
        $this->href ? 'a' : 'span',
        array(
          'id' => $this->id,
          'href'  => $this->href,
          'class' => implode(' ', $classes),
          'target' => $this->external ? '_blank' : null,
        ),
        array($icon, $content));
    }
  }

  public static function getTagTypes() {
    return array(
      self::TYPE_PERSON,
      self::TYPE_OBJECT,
      self::TYPE_STATE,
    );
  }

  public static function getColors() {
    return array(
      self::COLOR_RED,
      self::COLOR_ORANGE,
      self::COLOR_YELLOW,
      self::COLOR_BLUE,
      self::COLOR_INDIGO,
      self::COLOR_VIOLET,
      self::COLOR_GREEN,
      self::COLOR_BLACK,
      self::COLOR_GREY,
      self::COLOR_WHITE,

      self::COLOR_OBJECT,
      self::COLOR_PERSON,
    );
  }

  public function setExternal($external) {
    $this->external = $external;
    return $this;
  }

  public function getExternal() {
    return $this->external;
  }

}
