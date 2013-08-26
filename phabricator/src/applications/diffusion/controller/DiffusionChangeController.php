<?php

final class DiffusionChangeController extends DiffusionController {

  public function processRequest() {
    $drequest = $this->diffusionRequest;

    $content = array();

    $data = $this->callConduitWithDiffusionRequest(
      'diffusion.diffquery',
      array(
        'commit' => $drequest->getCommit(),
        'path' => $drequest->getPath()));
    $drequest->setCommit($data['effectiveCommit']);
    $raw_changes = ArcanistDiffChange::newFromConduit($data['changes']);
    $diff = DifferentialDiff::newFromRawChanges($raw_changes);
    $changesets = $diff->getChangesets();
    $changeset = reset($changesets);

    if (!$changeset) {
      // TODO: Refine this.
      return new Aphront404Response();
    }

    $repository = $drequest->getRepository();
    $callsign = $repository->getCallsign();
    $commit = $drequest->getRawCommit();
    $changesets = array(
      0 => $changeset,
    );

    $changeset_view = new DifferentialChangesetListView();
    $changeset_view->setTitle(DiffusionView::nameCommit($repository, $commit));
    $changeset_view->setChangesets($changesets);
    $changeset_view->setVisibleChangesets($changesets);
    $changeset_view->setRenderingReferences(
      array(
        0 => $drequest->generateURI(array('action' => 'rendering-ref'))
      ));

    $raw_params = array(
      'action' => 'browse',
      'params' => array(
        'view' => 'raw',
      ),
    );
    $right_uri = $drequest->generateURI($raw_params);
    $raw_params['params']['before'] = $drequest->getRawCommit();
    $left_uri = $drequest->generateURI($raw_params);
    $changeset_view->setRawFileURIs($left_uri, $right_uri);

    $changeset_view->setRenderURI(
      '/diffusion/'.$callsign.'/diff/');
    $changeset_view->setWhitespace(
      DifferentialChangesetParser::WHITESPACE_SHOW_ALL);
    $changeset_view->setUser($this->getRequest()->getUser());

    // TODO: This is pretty awkward, unify the CSS between Diffusion and
    // Differential better.
    require_celerity_resource('differential-core-view-css');
    $content[] = $changeset_view->render();

    $nav = $this->buildSideNav('change', true);
    $nav->appendChild($content);
    $crumbs = $this->buildCrumbs(
      array(
        'branch' => true,
        'path'   => true,
        'view'   => 'change',
      ));
    $nav->setCrumbs($crumbs);

    return $this->buildApplicationPage(
      $nav,
      array(
        'title' => pht('Change'),
        'device' => true,
      ));
  }

}
