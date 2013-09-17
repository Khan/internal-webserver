<?php

final class PHUIHeaderView extends AphrontView {

  const PROPERTY_STATE = 1;
  const PROPERTY_POLICY = 2;

  private $objectName;
  private $header;
  private $tags = array();
  private $image;
  private $subheader;
  private $gradient;
  private $noBackground;
  private $bleedHeader;
  private $properties;

  public function setHeader($header) {
    $this->header = $header;
    return $this;
  }

  public function setObjectName($object_name) {
    $this->objectName = $object_name;
    return $this;
  }

  public function setNoBackground($nada) {
    $this->noBackground = $nada;
    return $this;
  }

  public function addTag(PhabricatorTagView $tag) {
    $this->tags[] = $tag;
    return $this;
  }

  public function setImage($uri) {
    $this->image = $uri;
    return $this;
  }

  public function setSubheader($subheader) {
    $this->subheader = $subheader;
    return $this;
  }

  public function setBleedHeader($bleed) {
    $this->bleedHeader = $bleed;
    return $this;
  }

  public function setGradient($gradient) {
    $this->gradient = $gradient;
    return $this;
  }

  public function addProperty($property, $value) {
    $this->properties[$property] = $value;
    return $this;
  }

  public function render() {
    require_celerity_resource('phui-header-view-css');

    $classes = array();
    $classes[] = 'phui-header-shell';

    if ($this->noBackground) {
      $classes[] = 'phui-header-no-backgound';
    }

    if ($this->bleedHeader) {
      $classes[] = 'phabricator-bleed-header';
    }

    if ($this->gradient) {
      $classes[] = 'sprite-gradient';
      $classes[] = 'gradient-'.$this->gradient.'-header';
    }

    if ($this->properties || $this->subheader) {
      $classes[] = 'phui-header-tall';
    }

    $image = null;
    if ($this->image) {
      $image = phutil_tag(
        'span',
        array(
          'class' => 'phui-header-image',
          'style' => 'background-image: url('.$this->image.')',
        ),
        '');
      $classes[] = 'phui-header-has-image';
    }

    $header = array();
    $header[] = $this->header;

    if ($this->objectName) {
      array_unshift(
        $header,
        phutil_tag(
          'a',
          array(
            'href' => '/'.$this->objectName,
          ),
          $this->objectName),
        ' ');
    }

    if ($this->tags) {
      $header[] = ' ';
      $header[] = phutil_tag(
        'span',
        array(
          'class' => 'phui-header-tags',
        ),
        array_interleave(' ', $this->tags));
    }

    if ($this->subheader) {
      $header[] = phutil_tag(
        'div',
        array(
          'class' => 'phui-header-subheader',
        ),
        $this->subheader);
    }

    if ($this->properties) {
      $property_list = array();
      foreach ($this->properties as $type => $property) {
        switch ($type) {
          case self::PROPERTY_STATE:
            $property_list[] = $property;
          break;
          case self::PROPERTY_POLICY:
            $property_list[] = $property;
          break;
          default:
            throw new Exception('Incorrect Property Passed');
          break;
        }
      }

      $header[] = phutil_tag(
        'div',
        array(
          'class' => 'phui-header-subheader',
        ),
        $property_list);
    }

    return phutil_tag(
      'div',
      array(
        'class' => implode(' ', $classes),
      ),
      array(
        $image,
        phutil_tag(
          'h1',
          array(
            'class' => 'phui-header-view',
          ),
          $header),
      ));
  }


}
