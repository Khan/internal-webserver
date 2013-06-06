<?php

final class PhabricatorCrumbsView extends AphrontView {

  private $crumbs = array();
  private $actions = array();
  private $actionListID = null;

  protected function canAppendChild() {
    return false;
  }

  public function addCrumb(PhabricatorCrumbView $crumb) {
    $this->crumbs[] = $crumb;
    return $this;
  }

  public function addAction(PHUIListItemView $action) {
    $this->actions[] = $action;
    return $this;
  }

  public function setActionList(PhabricatorActionListView $list) {
    $this->actionListID = celerity_generate_unique_node_id();
    $list->setId($this->actionListID);
    return $this;
  }

  public function render() {
    require_celerity_resource('phabricator-crumbs-view-css');

    $action_view = null;
    if (($this->actions) || ($this->actionListID)) {
      $actions = array();
      foreach ($this->actions as $action) {
        $icon = null;
        if ($action->getIcon()) {
          $icon = phutil_tag(
            'span',
            array(
              'class' => 'sprite-icons icons-'.$action->getIcon(),
            ),
            '');
        }
        $name = phutil_tag(
          'span',
            array(
              'class' => 'phabricator-crumbs-action-name'
            ),
          $action->getName());

        $action_sigils = $action->getSigils();
        if ($action->getWorkflow()) {
          $action_sigils[] = 'workflow';
        }
        $action_classes = $action->getClasses();
        $action_classes[] = 'phabricator-crumbs-action';
        $actions[] = javelin_tag(
          'a',
          array(
            'href' => $action->getHref(),
            'class' => implode(' ', $action_classes),
            'sigil' => implode(' ', $action_sigils),
            'style' => $action->getStyle()
          ),
          array(
            $icon,
            $name,
          ));
      }

      if ($this->actionListID) {
        $icon_id = celerity_generate_unique_node_id();
        $icon = phutil_tag(
          'span',
            array(
              'class' => 'sprite-icons action-action-menu'
            ),
            '');
        $name = phutil_tag(
          'span',
            array(
              'class' => 'phabricator-crumbs-action-name'
            ),
          pht('Actions'));

        $actions[] = javelin_tag(
          'a',
            array(
              'href'  => '#',
              'class' =>
                'phabricator-crumbs-action phabricator-crumbs-action-menu',
              'sigil' => 'jx-toggle-class',
              'id'    => $icon_id,
              'meta'  => array(
                'map' => array(
                  $this->actionListID => 'phabricator-action-list-toggle',
                  $icon_id => 'phabricator-crumbs-action-menu-open'
                ),
              ),
            ),
            array(
              $icon,
              $name,
            ));
      }

      $action_view = phutil_tag(
        'div',
        array(
          'class' => 'phabricator-crumbs-actions',
        ),
        $actions);
    }

    if ($this->crumbs) {
      last($this->crumbs)->setIsLastCrumb(true);
    }

    return phutil_tag(
      'div',
      array(
        'class' => 'phabricator-crumbs-view '.
                   'sprite-gradient gradient-breadcrumbs',
      ),
      array(
        $action_view,
        $this->crumbs,
      ));
  }

}
