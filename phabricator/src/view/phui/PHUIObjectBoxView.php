<?php

final class PHUIObjectBoxView extends AphrontView {

  private $headerText;
  private $formError = null;
  private $form;
  private $validationException;
  private $header;
  private $flush;

  private $tabs = array();
  private $propertyLists = array();

  public function addPropertyList(
    PHUIPropertyListView $property_list,
    $tab = null) {

    if (!($tab instanceof PHUIListItemView) &&
        ($tab !== null)) {
      assert_stringlike($tab);
      $tab = id(new PHUIListItemView())->setName($tab);
    }

    if ($tab) {
      if ($tab->getKey()) {
        $key = $tab->getKey();
      } else {
        $key = 'tab.default.'.spl_object_hash($tab);
        $tab->setKey($key);
      }
    } else {
      $key = 'tab.default';
    }

    if ($tab) {
      if (empty($this->tabs[$key])) {
        $tab->addSigil('phui-object-box-tab');
        $tab->setMetadata(
          array(
            'tabKey' => $key,
          ));

        if (!$tab->getHref()) {
          $tab->setHref('#');
        }

        if (!$tab->getType()) {
          $tab->setType(PHUIListItemView::TYPE_LINK);
        }

        $this->tabs[$key] = $tab;
      }
    }

    $this->propertyLists[$key][] = $property_list;

    return $this;
  }

  public function setHeaderText($text) {
    $this->headerText = $text;
    return $this;
  }

  public function setFormError($error) {
    $this->formError = $error;
    return $this;
  }

  public function setForm($form) {
    $this->form = $form;
    return $this;
  }

  public function setHeader(PHUIHeaderView $header) {
    $this->header = $header;
    return $this;
  }

  public function setFlush($flush) {
    $this->flush = $flush;
    return $this;
  }

  public function setValidationException(
    PhabricatorApplicationTransactionValidationException $ex = null) {
    $this->validationException = $ex;
    return $this;
  }

  public function render() {

    require_celerity_resource('phui-object-box-css');

    if ($this->header) {
      $header = $this->header;
      $header->setGradient(PhabricatorActionHeaderView::HEADER_LIGHTBLUE);
    } else {
      $header = id(new PHUIHeaderView())
        ->setHeader($this->headerText)
        ->setGradient(PhabricatorActionHeaderView::HEADER_LIGHTBLUE);
    }

    $ex = $this->validationException;
    $exception_errors = null;
    if ($ex) {
      $messages = array();
      foreach ($ex->getErrors() as $error) {
        $messages[] = $error->getMessage();
      }
      if ($messages) {
        $exception_errors = id(new AphrontErrorView())
          ->setErrors($messages);
      }
    }

    $tab_lists = array();
    $property_lists = array();
    $tab_map = array();

    $default_key = 'tab.default';

    // Find the selected tab, or select the first tab if none are selected.
    if ($this->tabs) {
      $selected_tab = null;
      foreach ($this->tabs as $key => $tab) {
        if ($tab->getSelected()) {
          $selected_tab = $key;
          break;
        }
      }
      if ($selected_tab === null) {
        head($this->tabs)->setSelected(true);
        $selected_tab = head_key($this->tabs);
      }
    }

    foreach ($this->propertyLists as $key => $list) {
      $group = new PHUIPropertyGroupView();
      foreach ($list as $item) {
        $group->addPropertyList($item);
      }

      if ($this->tabs && $key != $default_key) {
        $tab_id = celerity_generate_unique_node_id();
        $tab_map[$key] = $tab_id;

        if ($key === $selected_tab) {
          $style = null;
        } else {
          $style = 'display: none';
        }

        $tab_lists[] = phutil_tag(
          'div',
          array(
            'style' => $style,
            'id' => $tab_id,
          ),
          $group);
      } else {
        if ($this->tabs) {
          $group->addClass('phui-property-group-noninitial');
        }
        $property_lists[] = $group;
      }
    }

    $tabs = null;
    if ($this->tabs) {
      $tabs = id(new PHUIListView())
        ->setType(PHUIListView::NAVBAR_LIST);
      foreach ($this->tabs as $tab) {
        $tabs->addMenuItem($tab);
      }

      Javelin::initBehavior('phui-object-box-tabs');
    }

    $content = id(new PHUIBoxView())
      ->appendChild(
        array(
          $header,
          $this->formError,
          $exception_errors,
          $this->form,
          $tabs,
          $tab_lists,
          $property_lists,
          $this->renderChildren(),
        ))
      ->setBorder(true)
      ->addMargin(PHUI::MARGIN_LARGE_TOP)
      ->addMargin(PHUI::MARGIN_LARGE_LEFT)
      ->addMargin(PHUI::MARGIN_LARGE_RIGHT)
      ->addClass('phui-object-box');

    if ($this->tabs) {
      $content->addSigil('phui-object-box');
      $content->setMetadata(
        array(
          'tabMap' => $tab_map,
        ));
    }

    if ($this->flush) {
      $content->addClass('phui-object-box-flush');
    }

    return $content;
  }
}
