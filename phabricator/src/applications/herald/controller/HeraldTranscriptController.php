<?php

final class HeraldTranscriptController extends HeraldController {

  const FILTER_AFFECTED = 'affected';
  const FILTER_OWNED    = 'owned';
  const FILTER_ALL      = 'all';

  private $id;
  private $filter;
  private $handles;
  private $adapter;

  public function willProcessRequest(array $data) {
    $this->id = $data['id'];
    $map = $this->getFilterMap();
    $this->filter = idx($data, 'filter');
    if (empty($map[$this->filter])) {
      $this->filter = self::FILTER_AFFECTED;
    }
  }

  private function getAdapter() {
    return $this->adapter;
  }

  public function processRequest() {

    $xscript = id(new HeraldTranscript())->load($this->id);
    if (!$xscript) {
      throw new Exception('Uknown transcript!');
    }

    require_celerity_resource('herald-test-css');

    $nav = $this->buildSideNav();

    $object_xscript = $xscript->getObjectTranscript();
    if (!$object_xscript) {
      $notice = id(new AphrontErrorView())
        ->setSeverity(AphrontErrorView::SEVERITY_NOTICE)
        ->setTitle(pht('Old Transcript'))
        ->appendChild(phutil_tag(
          'p',
          array(),
          pht('Details of this transcript have been garbage collected.')));
      $nav->appendChild($notice);
    } else {

      $this->adapter = HeraldAdapter::getAdapterForContentType(
        $object_xscript->getType());

      $filter = $this->getFilterPHIDs();
      $this->filterTranscript($xscript, $filter);
      $phids = array_merge($filter, $this->getTranscriptPHIDs($xscript));
      $phids = array_unique($phids);
      $phids = array_filter($phids);

      $handles = $this->loadViewerHandles($phids);
      $this->handles = $handles;

      if ($xscript->getDryRun()) {
        $notice = new AphrontErrorView();
        $notice->setSeverity(AphrontErrorView::SEVERITY_NOTICE);
        $notice->setTitle(pht('Dry Run'));
        $notice->appendChild(pht('This was a dry run to test Herald '.
          'rules, no actions were executed.'));
        $nav->appendChild($notice);
      }

      $apply_xscript_panel = $this->buildApplyTranscriptPanel(
        $xscript);
      $nav->appendChild($apply_xscript_panel);

      $action_xscript_panel = $this->buildActionTranscriptPanel(
        $xscript);
      $nav->appendChild($action_xscript_panel);

      $object_xscript_panel = $this->buildObjectTranscriptPanel(
        $xscript);
      $nav->appendChild($object_xscript_panel);
    }

    $crumbs = id($this->buildApplicationCrumbs())
      ->addCrumb(
        id(new PhabricatorCrumbView())
          ->setName(pht('Transcripts'))
          ->setHref($this->getApplicationURI('/transcript/')))
      ->addCrumb(
        id(new PhabricatorCrumbView())
          ->setName($xscript->getID()));
    $nav->setCrumbs($crumbs);

    return $this->buildApplicationPage(
      $nav,
      array(
        'title' => pht('Transcript'),
        'device' => true,
        'dust' => true,
      ));
  }

  protected function renderConditionTestValue($condition, $handles) {
    $value = $condition->getTestValue();
    if (!is_scalar($value) && $value !== null) {
      foreach ($value as $key => $phid) {
        $handle = idx($handles, $phid);
        if ($handle) {
          $value[$key] = $handle->getName();
        } else {
          // This shouldn't ever really happen as we are supposed to have
          // grabbed handles for everything, but be super liberal in what
          // we accept here since we expect all sorts of weird issues as we
          // version the system.
          $value[$key] = 'Unknown Object #'.$phid;
        }
      }
      sort($value);
      $value = implode(', ', $value);
    }

    return hsprintf('<span class="condition-test-value">%s</span>', $value);
  }

  private function buildSideNav() {
    $nav = new AphrontSideNavFilterView();
    $nav->setBaseURI(new PhutilURI('/herald/transcript/'.$this->id.'/'));

    $items = array();
    $filters = $this->getFilterMap();
    foreach ($filters as $key => $name) {
      $nav->addFilter($key, $name);
    }
    $nav->selectFilter($this->filter, null);

    return $nav;
  }

  protected function getFilterMap() {
    return array(
      self::FILTER_AFFECTED => pht('Rules that Affected Me'),
      self::FILTER_OWNED    => pht('Rules I Own'),
      self::FILTER_ALL      => pht('All Rules'),
    );
  }


  protected function getFilterPHIDs() {
    return array($this->getRequest()->getUser()->getPHID());
  }

  protected function getTranscriptPHIDs($xscript) {
    $phids = array();

    $object_xscript = $xscript->getObjectTranscript();
    if (!$object_xscript) {
      return array();
    }

    $phids[] = $object_xscript->getPHID();

    foreach ($xscript->getApplyTranscripts() as $apply_xscript) {
      // TODO: This is total hacks. Add another amazing layer of abstraction.
      $target = (array)$apply_xscript->getTarget();
      foreach ($target as $phid) {
        if ($phid) {
          $phids[] = $phid;
        }
      }
    }

    foreach ($xscript->getRuleTranscripts() as $rule_xscript) {
      $phids[] = $rule_xscript->getRuleOwner();
    }

    $condition_xscripts = $xscript->getConditionTranscripts();
    if ($condition_xscripts) {
      $condition_xscripts = call_user_func_array(
        'array_merge',
        $condition_xscripts);
    }
    foreach ($condition_xscripts as $condition_xscript) {
      $value = $condition_xscript->getTestValue();
      // TODO: Also total hacks.
      if (is_array($value)) {
        foreach ($value as $phid) {
          if ($phid) { // TODO: Probably need to make sure this "looks like" a
                       // PHID or decrease the level of hacks here; this used
                       // to be an is_numeric() check in Facebook land.
            $phids[] = $phid;
          }
        }
      }
    }

    return $phids;
  }

  protected function filterTranscript($xscript, $filter_phids) {
    $filter_owned = ($this->filter == self::FILTER_OWNED);
    $filter_affected = ($this->filter == self::FILTER_AFFECTED);

    if (!$filter_owned && !$filter_affected) {
      // No filtering to be done.
      return;
    }

    if (!$xscript->getObjectTranscript()) {
      return;
    }

    $user_phid = $this->getRequest()->getUser()->getPHID();

    $keep_apply_xscripts = array();
    $keep_rule_xscripts  = array();

    $filter_phids = array_fill_keys($filter_phids, true);

    $rule_xscripts = $xscript->getRuleTranscripts();
    foreach ($xscript->getApplyTranscripts() as $id => $apply_xscript) {
      $rule_id = $apply_xscript->getRuleID();
      if ($filter_owned) {
        if (empty($rule_xscripts[$rule_id])) {
          // No associated rule so you can't own this effect.
          continue;
        }
        if ($rule_xscripts[$rule_id]->getRuleOwner() != $user_phid) {
          continue;
        }
      } else if ($filter_affected) {
        $targets = (array)$apply_xscript->getTarget();
        if (!array_select_keys($filter_phids, $targets)) {
          continue;
        }
      }
      $keep_apply_xscripts[$id] = true;
      if ($rule_id) {
        $keep_rule_xscripts[$rule_id] = true;
      }
    }

    foreach ($rule_xscripts as $rule_id => $rule_xscript) {
      if ($filter_owned && $rule_xscript->getRuleOwner() == $user_phid) {
        $keep_rule_xscripts[$rule_id] = true;
      }
    }

    $xscript->setRuleTranscripts(
      array_intersect_key(
        $xscript->getRuleTranscripts(),
        $keep_rule_xscripts));

    $xscript->setApplyTranscripts(
      array_intersect_key(
        $xscript->getApplyTranscripts(),
        $keep_apply_xscripts));

    $xscript->setConditionTranscripts(
      array_intersect_key(
        $xscript->getConditionTranscripts(),
        $keep_rule_xscripts));
  }

  private function buildApplyTranscriptPanel($xscript) {
    $handles = $this->handles;
    $adapter = $this->getAdapter();

    $rule_type_global = HeraldRuleTypeConfig::RULE_TYPE_GLOBAL;
    $action_names = $adapter->getActionNameMap($rule_type_global);

    $rows = array();
    foreach ($xscript->getApplyTranscripts() as $apply_xscript) {

      $target = $apply_xscript->getTarget();
      switch ($apply_xscript->getAction()) {
        case HeraldAdapter::ACTION_NOTHING:
          $target = '';
          break;
        case HeraldAdapter::ACTION_FLAG:
          $target = PhabricatorFlagColor::getColorName($target);
          break;
        default:
          if ($target) {
            foreach ($target as $k => $phid) {
              $target[$k] = $handles[$phid]->getName();
            }
            $target = implode("\n", $target);
          } else {
            $target = '<empty>';
          }
          break;
      }

      if ($apply_xscript->getApplied()) {
        $success = pht('SUCCESS');
        $outcome =
          hsprintf('<span class="outcome-success">%s</span>', $success);
      } else {
        $failure = pht('FAILURE');
        $outcome =
          hsprintf('<span class="outcome-failure">%s</span>', $failure);
      }

      $rows[] = array(
        idx($action_names, $apply_xscript->getAction(), pht('Unknown')),
        $target,
        hsprintf(
          '<strong>Taken because:</strong> %s<br />'.
            '<strong>Outcome:</strong> %s %s',
          $apply_xscript->getReason(),
          $outcome,
          $apply_xscript->getAppliedReason()),
      );
    }

    $table = new AphrontTableView($rows);
    $table->setNoDataString(pht('No actions were taken.'));
    $table->setHeaders(
      array(
        pht('Action'),
        pht('Target'),
        pht('Details'),
      ));
    $table->setColumnClasses(
      array(
        '',
        '',
        'wide',
      ));

    $panel = new AphrontPanelView();
    $panel->setHeader(pht('Actions Taken'));
    $panel->appendChild($table);
    $panel->setNoBackground();

    return $panel;
  }

  private function buildActionTranscriptPanel($xscript) {
    $action_xscript = mgroup($xscript->getApplyTranscripts(), 'getRuleID');

    $adapter = $this->getAdapter();


    $field_names = $adapter->getFieldNameMap();
    $condition_names = $adapter->getConditionNameMap();

    $handles = $this->handles;

    $rule_markup = array();
    foreach ($xscript->getRuleTranscripts() as $rule_id => $rule) {
      $cond_markup = array();
      foreach ($xscript->getConditionTranscriptsForRule($rule_id) as $cond) {
        if ($cond->getNote()) {
          $note = hsprintf(
            '<div class="herald-condition-note">%s</div>',
            $cond->getNote());
        } else {
          $note = null;
        }

        if ($cond->getResult()) {
          $result = hsprintf(
            '<span class="herald-outcome condition-pass">'.
              "\xE2\x9C\x93".
            '</span>');
        } else {
          $result = hsprintf(
            '<span class="herald-outcome condition-fail">'.
              "\xE2\x9C\x98".
            '</span>');
        }

        $cond_markup[] = phutil_tag(
          'li',
          array(),
          pht(
            '%s Condition: %s %s %s%s',
            $result,
            idx($field_names, $cond->getFieldName(), pht('Unknown')),
            idx($condition_names, $cond->getCondition(), pht('Unknown')),
            $this->renderConditionTestValue($cond, $handles),
            $note));
      }

      if ($rule->getResult()) {
        $pass = pht('PASS');
        $result = hsprintf(
          '<span class="herald-outcome rule-pass">%s</span>', $pass);
        $class = 'herald-rule-pass';
      } else {
        $fail = pht('FAIL');
        $result = hsprintf(
          '<span class="herald-outcome rule-fail">%s</span>', $fail);
        $class = 'herald-rule-fail';
      }

      $cond_markup[] = hsprintf('<li>%s %s</li>', $result, $rule->getReason());
      $user_phid = $this->getRequest()->getUser()->getPHID();

      $name = $rule->getRuleName();

      $rule_markup[] =
        phutil_tag(
          'li',
          array(
            'class' => $class,
          ),
          hsprintf(
            '<div class="rule-name"><strong>%s</strong> %s</div>%s',
            $name,
            $handles[$rule->getRuleOwner()]->getName(),
            phutil_tag('ul', array(), $cond_markup)));
    }

    $panel = '';
    if ($rule_markup) {
      $panel = new AphrontPanelView();
      $panel->setHeader(pht('Rule Details'));
      $panel->setNoBackground();
      $panel->appendChild(phutil_tag(
        'ul',
        array('class' => 'herald-explain-list'),
        $rule_markup));
    }
    return $panel;
  }

  private function buildObjectTranscriptPanel($xscript) {

    $adapter = $this->getAdapter();
    $field_names = $adapter->getFieldNameMap();

    $object_xscript = $xscript->getObjectTranscript();

    $data = array();
    if ($object_xscript) {
      $phid = $object_xscript->getPHID();
      $handles = $this->loadViewerHandles(array($phid));

      $data += array(
        pht('Object Name') => $object_xscript->getName(),
        pht('Object Type') => $object_xscript->getType(),
        pht('Object PHID') => $phid,
        pht('Object Link') => $handles[$phid]->renderLink(),
      );
    }

    $data += $xscript->getMetadataMap();

    if ($object_xscript) {
      foreach ($object_xscript->getFields() as $field => $value) {
        $field = idx($field_names, $field, '['.$field.'?]');
        $data['Field: '.$field] = $value;
      }
    }

    $rows = array();
    foreach ($data as $name => $value) {
      if (!($value instanceof PhutilSafeHTML)) {
        if (!is_scalar($value) && !is_null($value)) {
          $value = implode("\n", $value);
        }

        if (strlen($value) > 256) {
          $value = phutil_tag(
            'textarea',
            array(
              'class' => 'herald-field-value-transcript',
            ),
            $value);
        }
      }

      $rows[] = array($name, $value);
    }

    $table = new AphrontTableView($rows);
    $table->setColumnClasses(
      array(
        'header',
        'wide',
      ));

    $panel = new AphrontPanelView();
    $panel->setHeader(pht('Object Transcript'));
    $panel->setNoBackground();
    $panel->appendChild($table);

    return $panel;
  }


}
