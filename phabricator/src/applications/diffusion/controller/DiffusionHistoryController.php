<?php

final class DiffusionHistoryController extends DiffusionController {

  public function shouldAllowPublic() {
    return true;
  }

  public function processRequest() {
    $drequest = $this->diffusionRequest;
    $request = $this->getRequest();
    $viewer = $request->getUser();
    $repository = $drequest->getRepository();

    $page_size = $request->getInt('pagesize', 100);
    $offset = $request->getInt('offset', 0);

    $params = array(
      'commit' => $drequest->getCommit(),
      'path' => $drequest->getPath(),
      'offset' => $offset,
      'limit' => $page_size + 1);

    if (!$request->getBool('copies')) {
      $params['needDirectChanges'] = true;
      $params['needChildChanges'] = true;
    }

    $history_results = $this->callConduitWithDiffusionRequest(
      'diffusion.historyquery',
      $params);
    $history = DiffusionPathChange::newFromConduit(
      $history_results['pathChanges']);

    $pager = new AphrontPagerView();
    $pager->setPageSize($page_size);
    $pager->setOffset($offset);
    $history = $pager->sliceResults($history);

    $pager->setURI($request->getRequestURI(), 'offset');

    $show_graph = !strlen($drequest->getPath());
    $content = array();

    $history_table = new DiffusionHistoryTableView();
    $history_table->setUser($request->getUser());
    $history_table->setDiffusionRequest($drequest);
    $history_table->setHistory($history);
    $history_table->loadRevisions();

    $phids = $history_table->getRequiredHandlePHIDs();
    $handles = $this->loadViewerHandles($phids);
    $history_table->setHandles($handles);

    if ($show_graph) {
      $history_table->setParents($history_results['parents']);
      $history_table->setIsHead($offset == 0);
    }

    $history_panel = new AphrontPanelView();
    $history_panel->appendChild($history_table);
    $history_panel->appendChild($pager);
    $history_panel->setNoBackground();

    $content[] = $history_panel;

    $header = id(new PHUIHeaderView())
      ->setUser($viewer)
      ->setPolicyObject($repository)
      ->setHeader($this->renderPathLinks($drequest, $mode = 'history'));

    $actions = $this->buildActionView($drequest);
    $properties = $this->buildPropertyView($drequest);

    $crumbs = $this->buildCrumbs(
      array(
        'branch' => true,
        'path'   => true,
        'view'   => 'history',
      ));

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $header,
        $actions,
        $properties,
        $content,
      ),
      array(
        'device' => true,
        'title' => array(
          pht('History'),
          pht('%s Repository', $drequest->getRepository()->getCallsign()),
        ),
      ));
  }

  private function buildActionView(DiffusionRequest $drequest) {
    $viewer = $this->getRequest()->getUser();

    $view = id(new PhabricatorActionListView())
      ->setUser($viewer);

    $browse_uri = $drequest->generateURI(
      array(
        'action' => 'browse',
      ));

    $view->addAction(
      id(new PhabricatorActionView())
        ->setName(pht('Browse Content'))
        ->setHref($browse_uri)
        ->setIcon('file'));

    // TODO: Sometimes we do have a change view, we need to look at the most
    // recent history entry to figure it out.

    $request = $this->getRequest();
    if ($request->getBool('copies')) {
      $branch_name = pht('Hide Copies/Branches');
      $branch_icon = 'fork-grey';
      $branch_uri = $request->getRequestURI()
        ->alter('offset', null)
        ->alter('copies', null);
    } else {
      $branch_name = pht('Show Copies/Branches');
      $branch_icon = 'fork';
      $branch_uri = $request->getRequestURI()
        ->alter('offset', null)
        ->alter('copies', true);
    }

    $view->addAction(
      id(new PhabricatorActionView())
        ->setName($branch_name)
        ->setIcon($branch_icon)
        ->setHref($branch_uri));

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
