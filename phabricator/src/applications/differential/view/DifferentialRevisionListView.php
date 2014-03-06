<?php

/**
 * Render a table of Differential revisions.
 */
final class DifferentialRevisionListView extends AphrontView {

  private $revisions;
  private $handles;
  private $fields;
  private $highlightAge;
  private $header;
  private $noDataString;

  public function setNoDataString($no_data_string) {
    $this->noDataString = $no_data_string;
    return $this;
  }

  public function setHeader($header) {
    $this->header = $header;
    return $this;
  }

  public function setFields(array $fields) {
    assert_instances_of($fields, 'DifferentialFieldSpecification');
    $this->fields = $fields;
    return $this;
  }

  public function setRevisions(array $revisions) {
    assert_instances_of($revisions, 'DifferentialRevision');
    $this->revisions = $revisions;
    return $this;
  }

  public function setHighlightAge($bool) {
    $this->highlightAge = $bool;
    return $this;
  }

  public function getRequiredHandlePHIDs() {
    $phids = array();
    foreach ($this->fields as $field) {
      foreach ($this->revisions as $revision) {
        $phids[] = $field->getRequiredHandlePHIDsForRevisionList($revision);
      }
    }
    return array_mergev($phids);
  }

  public function setHandles(array $handles) {
    assert_instances_of($handles, 'PhabricatorObjectHandle');
    $this->handles = $handles;
    return $this;
  }

  public function render() {

    $user = $this->user;
    if (!$user) {
      throw new Exception("Call setUser() before render()!");
    }

    $fresh = PhabricatorEnv::getEnvConfig('differential.days-fresh');
    if ($fresh) {
      $fresh = PhabricatorCalendarHoliday::getNthBusinessDay(
        time(),
        -$fresh);
    }

    $stale = PhabricatorEnv::getEnvConfig('differential.days-stale');
    if ($stale) {
      $stale = PhabricatorCalendarHoliday::getNthBusinessDay(
        time(),
        -$stale);
    }

    $this->initBehavior('phabricator-tooltips', array());
    $this->requireResource('aphront-tooltip-css');

    foreach ($this->fields as $field) {
      $field->setHandles($this->handles);
    }

    $list = new PHUIObjectItemListView();
    $list->setCards(true);

    foreach ($this->revisions as $revision) {
      $item = id(new PHUIObjectItemView())
        ->setUser($user);

      $rev_fields = array();
      $icons = array();

      $phid = $revision->getPHID();
      $flag = $revision->getFlag($user);
      if ($flag) {
        $flag_class = PhabricatorFlagColor::getCSSClass($flag->getColor());
        $icons['flag'] = phutil_tag(
          'div',
          array(
            'class' => 'phabricator-flag-icon '.$flag_class,
          ),
          '');
      }

      if ($revision->getDrafts($user)) {
        $icons['draft'] = true;
      }

      $modified = $revision->getDateModified();

      $status = $revision->getStatus();
      $show_age = ($fresh || $stale) &&
                  $this->highlightAge &&
                  !$revision->isClosed();

      $object_age = PHUIObjectItemView::AGE_FRESH;
      foreach ($this->fields as $field) {
        if ($show_age) {
          if ($field instanceof DifferentialDateModifiedFieldSpecification) {
            if ($stale && $modified < $stale) {
              $object_age = PHUIObjectItemView::AGE_OLD;
            } else if ($fresh && $modified < $fresh) {
              $object_age = PHUIObjectItemView::AGE_STALE;
            }
          }
        }

        $rev_header = $field->renderHeaderForRevisionList();
        $rev_fields[$rev_header] = $field
          ->renderValueForRevisionList($revision);
      }

      $status_name =
        ArcanistDifferentialRevisionStatus::getNameForRevisionStatus($status);

      if (isset($icons['flag'])) {
        $item->addHeadIcon($icons['flag']);
      }

      $item->setObjectName('D'.$revision->getID());
      $item->setHeader(phutil_tag('a',
        array('href' => '/D'.$revision->getID()),
        $revision->getTitle()));

      if (isset($icons['draft'])) {
        $draft = id(new PHUIIconView())
          ->setSpriteSheet(PHUIIconView::SPRITE_ICONS)
          ->setSpriteIcon('file-grey')
          ->addSigil('has-tooltip')
          ->setMetadata(
            array(
              'tip' => pht('Unsubmitted Comments'),
            ));
        $item->addAttribute($draft);
      }

      $item->addAttribute($status_name);

      // Author
      $author_handle = $this->handles[$revision->getAuthorPHID()];
      $item->addByline(pht('Author: %s', $author_handle->renderLink()));

      // Reviewers
      $item->addAttribute(pht('Reviewers: %s', $rev_fields['Reviewers']));

      $item->setEpoch($revision->getDateModified(), $object_age);

      // First remove the fields we already have
      $count = 7;
      $rev_fields = array_slice($rev_fields, $count);

      // Then add each one of them
      // TODO: Add render-to-foot-icon support
      foreach ($rev_fields as $header => $field) {
        $item->addAttribute(pht('%s: %s', $header, $field));
      }

      switch ($status) {
        case ArcanistDifferentialRevisionStatus::NEEDS_REVIEW:
          break;
        case ArcanistDifferentialRevisionStatus::NEEDS_REVISION:
        case ArcanistDifferentialRevisionStatus::CHANGES_PLANNED:
          $item->setBarColor('red');
          break;
        case ArcanistDifferentialRevisionStatus::ACCEPTED:
          $item->setBarColor('green');
          break;
        case ArcanistDifferentialRevisionStatus::CLOSED:
          $item->setDisabled(true);
          break;
        case ArcanistDifferentialRevisionStatus::ABANDONED:
          $item->setBarColor('black');
          break;
      }

      $list->addItem($item);
    }

    $list->setHeader($this->header);
    $list->setNoDataString($this->noDataString);

    return $list;
  }

  public static function getDefaultFields(PhabricatorUser $user) {
    $selector = DifferentialFieldSelector::newSelector();
    $fields = $selector->getFieldSpecifications();
    foreach ($fields as $key => $field) {
      $field->setUser($user);
      if (!$field->shouldAppearOnRevisionList()) {
        unset($fields[$key]);
      }
    }

    if (!$fields) {
      throw new Exception(
        "Phabricator configuration has no fields that appear on the list ".
        "interface!");
    }

    return $selector->sortFieldsForRevisionList($fields);
  }

}
