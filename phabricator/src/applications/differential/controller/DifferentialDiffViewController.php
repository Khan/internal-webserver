<?php

final class DifferentialDiffViewController extends DifferentialController {

  private $id;

  public function willProcessRequest(array $data) {
    $this->id = $data['id'];
  }

  public function processRequest() {
    $request = $this->getRequest();

    $diff = id(new DifferentialDiff())->load($this->id);
    if (!$diff) {
      return new Aphront404Response();
    }

    if ($diff->getRevisionID()) {
      $top_panel = new AphrontPanelView();
      $top_panel->setWidth(AphrontPanelView::WIDTH_WIDE);
      $link = phutil_render_tag(
        'a',
        array(
          'href' => PhabricatorEnv::getURI('/D'.$diff->getRevisionID()),
        ),
        phutil_escape_html('D'.$diff->getRevisionID()));
      $top_panel->appendChild("<h1>This diff belongs to revision {$link}</h1>");
    } else {
      $action_panel = new AphrontPanelView();
      $action_panel->setHeader('Preview Diff');
      $action_panel->setWidth(AphrontPanelView::WIDTH_WIDE);
      $action_panel->appendChild(
        '<p class="aphront-panel-instructions">Review the diff for '.
        'correctness. When you are satisfied, either <strong>create a new '.
        'revision</strong> or <strong>update an existing revision</strong>.');

      // TODO: implmenent optgroup support in AphrontFormSelectControl?
      $select = array();
      $select[] = '<optgroup label="Create New Revision">';
      $select[] = '<option value="">Create a new Revision...</option>';
      $select[] = '</optgroup>';

      $revision_data = new DifferentialRevisionListData(
        DifferentialRevisionListData::QUERY_OPEN_OWNED,
        array($request->getUser()->getPHID()));
      $revisions = $revision_data->loadRevisions();

      if ($revisions) {
        $select[] = '<optgroup label="Update Existing Revision">';
        foreach ($revisions as $revision) {
          $select[] = phutil_render_tag(
            'option',
            array(
              'value' => $revision->getID(),
            ),
            phutil_escape_html($revision->getTitle()));
        }
        $select[] = '</optgroup>';
      }

      $select =
        '<select name="revisionID">'.
        implode("\n", $select).
        '</select>';

      $action_form = new AphrontFormView();
      $action_form
        ->setUser($request->getUser())
        ->setAction('/differential/revision/edit/')
        ->addHiddenInput('diffID', $diff->getID())
        ->addHiddenInput('viaDiffView', 1)
        ->appendChild(
          id(new AphrontFormMarkupControl())
          ->setLabel('Attach To')
          ->setValue($select))
        ->appendChild(
          id(new AphrontFormSubmitControl())
          ->setValue('Continue'));

      $action_panel->appendChild($action_form);

      $top_panel = $action_panel;
    }

    $arc_unit = id(new DifferentialDiffProperty())->loadOneWhere(
      'diffID = %d and name = %s',
      $this->id,
      'arc:unit');
    if ($arc_unit) {
      $test_data = array($arc_unit->getName() => $arc_unit->getData());
    } else {
      $test_data = array();
    }

    $changesets = $diff->loadChangesets();
    $changesets = msort($changesets, 'getSortKey');

    $table_of_contents = id(new DifferentialDiffTableOfContentsView())
      ->setChangesets($changesets)
      ->setVisibleChangesets($changesets)
      ->setUnitTestData($test_data);

    $refs = array();
    foreach ($changesets as $changeset) {
      $refs[$changeset->getID()] = $changeset->getID();
    }

    $details = id(new DifferentialChangesetListView())
      ->setChangesets($changesets)
      ->setVisibleChangesets($changesets)
      ->setLineWidthFromChangesets($changesets)
      ->setRenderingReferences($refs)
      ->setStandaloneURI('/differential/changeset/')
      ->setUser($request->getUser());

    return $this->buildStandardPageResponse(
      id(new DifferentialPrimaryPaneView())
        ->setLineWidthFromChangesets($changesets)
        ->appendChild(
          array(
            $top_panel->render(),
            $table_of_contents->render(),
            $details->render(),
          )),
      array(
        'title' => 'Diff View',
      ));
  }

}
