<?php

final class PHUIPropertyListView extends AphrontView {

  private $parts = array();
  private $hasKeyboardShortcuts;
  private $object;
  private $invokedWillRenderEvent;
  private $actionList;

  protected function canAppendChild() {
    return false;
  }

  public function setObject($object) {
    $this->object = $object;
    return $this;
  }

  public function setActionList(PhabricatorActionListView $list) {
    $this->actionList = $list;
    return $this;
  }

  public function setHasKeyboardShortcuts($has_keyboard_shortcuts) {
    $this->hasKeyboardShortcuts = $has_keyboard_shortcuts;
    return $this;
  }

  public function addProperty($key, $value) {
    $current = array_pop($this->parts);

    if (!$current || $current['type'] != 'property') {
      if ($current) {
        $this->parts[] = $current;
      }
      $current = array(
        'type' => 'property',
        'list' => array(),
      );
    }

    $current['list'][] = array(
      'key'   => $key,
      'value' => $value,
    );

    $this->parts[] = $current;
    return $this;
  }

  public function addSectionHeader($name) {
    $this->parts[] = array(
      'type' => 'section',
      'name' => $name,
    );
    return $this;
  }

  public function addTextContent($content) {
    $this->parts[] = array(
      'type'    => 'text',
      'content' => $content,
    );
    return $this;
  }

  public function addImageContent($content) {
    $this->parts[] = array(
      'type'    => 'image',
      'content' => $content,
    );
    return $this;
  }

  public function invokeWillRenderEvent() {
    if ($this->object && $this->getUser() && !$this->invokedWillRenderEvent) {
      $event = new PhabricatorEvent(
        PhabricatorEventType::TYPE_UI_WILLRENDERPROPERTIES,
        array(
          'object'  => $this->object,
          'view'    => $this,
        ));
      $event->setUser($this->getUser());
      PhutilEventEngine::dispatchEvent($event);
    }
    $this->invokedWillRenderEvent = true;
  }

  public function render() {
    $this->invokeWillRenderEvent();

    require_celerity_resource('phui-property-list-view-css');

    $items = array();
    foreach ($this->parts as $part) {
      $type = $part['type'];
      switch ($type) {
        case 'property':
          $items[] = $this->renderPropertyPart($part);
          break;
        case 'section':
          $items[] = $this->renderSectionPart($part);
          break;
        case 'text':
        case 'image':
          $items[] = $this->renderTextPart($part);
          break;
        default:
          throw new Exception(pht("Unknown part type '%s'!", $type));
      }
    }

    return phutil_tag(
      'div',
      array(
        'class' => 'phui-property-list-section',
      ),
      array(
        $items,
      ));
  }

  private function renderPropertyPart(array $part) {
    $items = array();
    foreach ($part['list'] as $spec) {
      $key = $spec['key'];
      $value = $spec['value'];

      // NOTE: We append a space to each value to improve the behavior when the
      // user double-clicks a property value (like a URI) to select it. Without
      // the space, the label is also selected.

      $items[] = phutil_tag(
        'dt',
        array(
          'class' => 'phui-property-list-key',
        ),
        array($key, ' '));

      $items[] = phutil_tag(
        'dd',
        array(
          'class' => 'phui-property-list-value',
        ),
        array($value, ' '));
    }

    $list = phutil_tag(
      'dl',
      array(
        'class' => 'phui-property-list-properties',
      ),
      $items);

    $shortcuts = null;
    if ($this->hasKeyboardShortcuts) {
      $shortcuts = new AphrontKeyboardShortcutsAvailableView();
    }

    $list = phutil_tag(
      'div',
      array(
        'class' => 'phui-property-list-properties-wrap',
      ),
      array($shortcuts, $list));

    $action_list = null;
    if ($this->actionList) {
      $action_list = phutil_tag(
        'div',
        array(
          'class' => 'phui-property-list-actions',
        ),
        $this->actionList);
      $this->actionList = null;
    }

    return phutil_tag(
        'div',
        array(
          'class' => 'phui-property-list-container grouped',
        ),
        array($action_list, $list));
  }

  private function renderSectionPart(array $part) {
    return phutil_tag(
      'div',
      array(
        'class' => 'phui-property-list-section-header',
      ),
      $part['name']);
  }

  private function renderTextPart(array $part) {
    $classes = array();
    $classes[] = 'phui-property-list-text-content';
    if ($part['type'] == 'image') {
      $classes[] = 'phui-property-list-image-content';
    }
    return phutil_tag(
      'div',
      array(
        'class' => implode($classes, ' '),
      ),
      $part['content']);
  }

}
