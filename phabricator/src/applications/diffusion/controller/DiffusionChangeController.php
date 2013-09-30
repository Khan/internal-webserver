<?php

final class DiffusionChangeController extends DiffusionController {

  public function shouldAllowPublic() {
    return true;
  }

  public function processRequest() {
    $drequest = $this->diffusionRequest;
    $viewer = $this->getRequest()->getUser();

    $content = array();

    $data = $this->callConduitWithDiffusionRequest(
      'diffusion.diffquery',
      array(
        'commit' => $drequest->getCommit(),
        'path' => $drequest->getPath(),
      ));
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

    $changeset_view->setRenderURI('/diffusion/'.$callsign.'/diff/');
    $changeset_view->setWhitespace(
      DifferentialChangesetParser::WHITESPACE_SHOW_ALL);
    $changeset_view->setUser($this->getRequest()->getUser());

    // TODO: This is pretty awkward, unify the CSS between Diffusion and
    // Differential better.
    require_celerity_resource('differential-core-view-css');
    $content[] = $changeset_view->render();

    $crumbs = $this->buildCrumbs(
      array(
        'branch' => true,
        'path'   => true,
        'view'   => 'change',
      ));

    $links = $this->renderPathLinks($drequest, $mode = 'browse');

    $header = id(new PHUIHeaderView())
      ->setHeader($links)
      ->setUser($viewer)
      ->setPolicyObject($drequest->getRepository());
    $actions = $this->buildActionView($drequest);
    $properties = $this->buildPropertyView($drequest);

    $object_box = id(new PHUIObjectBoxView())
      ->setHeader($header)
      ->setActionList($actions)
      ->setPropertyList($properties);

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $object_box,
        $content,
      ),
      array(
        'title' => pht('Change'),
      ));
  }

  private function buildActionView(DiffusionRequest $drequest) {
    $viewer = $this->getRequest()->getUser();

    $view = id(new PhabricatorActionListView())
      ->setUser($viewer);

    $history_uri = $drequest->generateURI(
      array(
        'action' => 'history',
      ));

    $view->addAction(
      id(new PhabricatorActionView())
        ->setName(pht('View History'))
        ->setHref($history_uri)
        ->setIcon('history'));

    $browse_uri = $drequest->generateURI(
      array(
        'action' => 'browse',
      ));

    $view->addAction(
      id(new PhabricatorActionView())
        ->setName(pht('Browse Content'))
        ->setHref($browse_uri)
        ->setIcon('file'));

    return $view;
  }

  protected function buildPropertyView(DiffusionRequest $drequest) {
    $viewer = $this->getRequest()->getUser();

    $view = id(new PhabricatorPropertyListView())
      ->setUser($viewer);

    $stable_commit = $drequest->getStableCommitName();
    $callsign = $drequest->getRepository()->getCallsign();

    $view->addProperty(
      pht('Commit'),
      phutil_tag(
        'a',
        array(
          'href' => $drequest->generateURI(
            array(
              'action' => 'commit',
              'commit' => $stable_commit,
            )),
        ),
        $drequest->getRepository()->formatCommitName($stable_commit)));

    return $view;
  }

}
