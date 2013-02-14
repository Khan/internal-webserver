<?php

final class HeraldRuleEditHistoryView extends AphrontView {

  private $edits;
  private $handles;

  public function setEdits(array $edits) {
    $this->edits = $edits;
    return $this;
  }

  public function getEdits() {
    return $this->edits;
  }

  public function setHandles(array $handles) {
    assert_instances_of($handles, 'PhabricatorObjectHandle');
    $this->handles = $handles;
    return $this;
  }

  public function render() {
    $rows = array();

    foreach ($this->edits as $edit) {
      $name = nonempty($edit->getRuleName(), 'Unknown Rule');
      $rule_name = phutil_tag(
        'strong',
        array(),
        $name);

      switch ($edit->getAction()) {
        case 'create':
          $details = pht("Created rule '%s'.", $rule_name);
          break;
        case 'delete':
          $details = pht("Deleted rule '%s'.", $rule_name);
          break;
        case 'edit':
        default:
          $details = pht("Edited rule '%s'.", $rule_name);
          break;
      }

      $rows[] = array(
        $edit->getRuleID(),
        $this->handles[$edit->getEditorPHID()]->renderLink(),
        $details,
        phabricator_datetime($edit->getDateCreated(), $this->user),
      );
    }

    $table = new AphrontTableView($rows);
    $table->setNoDataString("No edits for rule.");
    $table->setHeaders(
      array(
        'Rule ID',
        'Editor',
        'Details',
        'Edit Date',
      ));
    $table->setColumnClasses(
      array(
        '',
        '',
        'wide',
        '',
      ));

    return $table->render();
  }
}
