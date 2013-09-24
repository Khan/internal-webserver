<?php

final class DiffusionLintController extends DiffusionController {

  public function shouldAllowPublic() {
    return true;
  }

  public function processRequest() {
    $request = $this->getRequest();
    $user = $this->getRequest()->getUser();
    $drequest = $this->diffusionRequest;

    if ($request->getStr('lint') !== null) {
      $controller = new DiffusionLintDetailsController($request);
      $controller->setDiffusionRequest($drequest);
      $controller->setCurrentApplication($this->getCurrentApplication());
      return $this->delegateToController($controller);
    }

    $owners = array();
    if (!$drequest) {
      if (!$request->getArr('owner')) {
        if ($user->isLoggedIn()) {
          $owners[$user->getPHID()] = $user->getFullName();
        }
      } else {
        $phids = $request->getArr('owner');
        $phid = reset($phids);
        $handles = $this->loadViewerHandles(array($phid));
        $owners[$phid] = $handles[$phid]->getFullName();
      }
    }

    $codes = $this->loadLintCodes(array_keys($owners));

    if ($codes && !$drequest) {
      // TODO: Build some real Query classes for this stuff.

      $branches = id(new PhabricatorRepositoryBranch())->loadAllWhere(
        'id IN (%Ld)',
        array_unique(ipull($codes, 'branchID')));

      $repositories = id(new PhabricatorRepositoryQuery())
        ->setViewer($user)
        ->withIDs(mpull($branches, 'getRepositoryID'))
        ->execute();

      $drequests = array();
      foreach ($branches as $id => $branch) {
        if (empty($repositories[$branch->getRepositoryID()])) {
          continue;
        }

        $drequests[$id] = DiffusionRequest::newFromDictionary(array(
          'user' => $user,
          'repository' => $repositories[$branch->getRepositoryID()],
          'branch' => $branch->getName(),
        ));
      }
    }

    $rows = array();
    $total = 0;
    foreach ($codes as $code) {
      if (!$this->diffusionRequest) {
        $drequest = idx($drequests, $code['branchID']);
      }

      if (!$drequest) {
        continue;
      }

      $total += $code['n'];

      $rows[] = array(
        hsprintf(
          '<a href="%s">%s</a>',
          $drequest->generateURI(array(
            'action' => 'lint',
            'lint' => $code['code'],
          )),
          $code['n']),
        hsprintf(
          '<a href="%s">%s</a>',
          $drequest->generateURI(array(
            'action' => 'browse',
            'lint' => $code['code'],
          )),
          $code['files']),
        hsprintf(
          '<a href="%s">%s</a>',
          $drequest->generateURI(array('action' => 'lint')),
          $drequest->getCallsign()),
        ArcanistLintSeverity::getStringForSeverity($code['maxSeverity']),
        $code['code'],
        $code['maxName'],
        $code['maxDescription'],
      );
    }

    $table = id(new AphrontTableView($rows))
      ->setHeaders(array(
        pht('Problems'),
        pht('Files'),
        pht('Repository'),
        pht('Severity'),
        pht('Code'),
        pht('Name'),
        pht('Example'),
      ))
      ->setColumnVisibility(array(true, true, !$this->diffusionRequest))
      ->setColumnClasses(array('n', 'n', '', '', 'pri', '', ''));

    $content = array();

    $link = null;
    if (!$this->diffusionRequest) {
      $form = id(new AphrontFormView())
        ->setUser($user)
        ->setMethod('GET')
        ->appendChild(
          id(new AphrontFormTokenizerControl())
            ->setDatasource('/typeahead/common/users/')
            ->setLimit(1)
            ->setName('owner')
            ->setLabel(pht('Owner'))
            ->setValue($owners))
        ->appendChild(
          id(new AphrontFormSubmitControl())
            ->setValue('Filter'));
      $content[] = id(new AphrontListFilterView())->appendChild($form);
    }

    $content[] = id(new AphrontPanelView())
      ->setNoBackground(true)
      ->setCaption($link)
      ->appendChild($table);

    $title = array('Lint');
    $crumbs = $this->buildCrumbs(
      array(
        'branch' => true,
        'path'   => true,
        'view'   => 'lint',
      ));

    if ($this->diffusionRequest) {
      $title[] = $drequest->getCallsign();
    } else {
      $crumbs->addCrumb(
        id(new PhabricatorCrumbView())
          ->setName(pht('All Lint')));
    }

    if ($this->diffusionRequest) {
      $branch = $drequest->loadBranch();

      $header = id(new PHUIHeaderView())
        ->setHeader($this->renderPathLinks($drequest, 'lint'))
        ->setUser($user)
        ->setPolicyObject($drequest->getRepository());
      $actions = $this->buildActionView($drequest);
      $properties = $this->buildPropertyView(
        $drequest,
        $branch,
        $total);
    } else {
      $header = null;
      $actions = null;
      $properties = null;
    }


    return $this->buildApplicationPage(
      array(
        $crumbs,
        $header,
        $actions,
        $properties,
        $content,
      ),
      array(
        'title' => $title,
      ));
  }

  private function loadLintCodes(array $owner_phids) {
    $drequest = $this->diffusionRequest;
    $conn = id(new PhabricatorRepository())->establishConnection('r');
    $where = array('1 = 1');

    if ($drequest) {
      $branch = $drequest->loadBranch();
      if (!$branch) {
        return array();
      }

      $where[] = qsprintf($conn, 'branchID = %d', $branch->getID());

      if ($drequest->getPath() != '') {
        $path = '/'.$drequest->getPath();
        $is_dir = (substr($path, -1) == '/');
        $where[] = ($is_dir
          ? qsprintf($conn, 'path LIKE %>', $path)
          : qsprintf($conn, 'path = %s', $path));
      }
    }

    if ($owner_phids) {
      $or = array();
      $or[] = qsprintf($conn, 'authorPHID IN (%Ls)', $owner_phids);

      $paths = array();
      $packages = id(new PhabricatorOwnersOwner())
        ->loadAllWhere('userPHID IN (%Ls)', $owner_phids);
      if ($packages) {
        $paths = id(new PhabricatorOwnersPath())->loadAllWhere(
          'packageID IN (%Ld)',
          mpull($packages, 'getPackageID'));
      }

      if ($paths) {
        $repositories = id(new PhabricatorRepository())->loadAllWhere(
          'phid IN (%Ls)',
          array_unique(mpull($paths, 'getRepositoryPHID')));
        $repositories = mpull($repositories, 'getID', 'getPHID');

        $branches = id(new PhabricatorRepositoryBranch())->loadAllWhere(
          'repositoryID IN (%Ld)',
          $repositories);
        $branches = mgroup($branches, 'getRepositoryID');
      }

      foreach ($paths as $path) {
        $branch = idx($branches, $repositories[$path->getRepositoryPHID()]);
        if ($branch) {
          $condition = qsprintf(
            $conn,
            '(branchID IN (%Ld) AND path LIKE %>)',
            array_keys($branch),
            $path->getPath());
          if ($path->getExcluded()) {
            $where[] = 'NOT '.$condition;
          } else {
            $or[] = $condition;
          }
        }
      }
      $where[] = '('.implode(' OR ', $or).')';
    }

    return queryfx_all(
      $conn,
      'SELECT
          branchID,
          code,
          MAX(severity) AS maxSeverity,
          MAX(name) AS maxName,
          MAX(description) AS maxDescription,
          COUNT(DISTINCT path) AS files,
          COUNT(*) AS n
        FROM %T
        WHERE %Q
        GROUP BY branchID, code
        ORDER BY n DESC',
      PhabricatorRepository::TABLE_LINTMESSAGE,
      implode(' AND ', $where));
  }

  protected function buildActionView(DiffusionRequest $drequest) {
    $viewer = $this->getRequest()->getUser();

    $view = id(new PhabricatorActionListView())
      ->setUser($viewer);

    $list_uri = $drequest->generateURI(
      array(
        'action' => 'lint',
        'lint' => '',
      ));

    $view->addAction(
      id(new PhabricatorActionView())
        ->setName(pht('View As List'))
        ->setHref($list_uri)
        ->setIcon('transcript'));

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

  protected function buildPropertyView(
    DiffusionRequest $drequest,
    PhabricatorRepositoryBranch $branch,
    $total) {

    $viewer = $this->getRequest()->getUser();

    $view = id(new PhabricatorPropertyListView())
      ->setUser($viewer);

    $callsign = $drequest->getRepository()->getCallsign();
    $lint_commit = $branch->getLintCommit();

    $view->addProperty(
      pht('Lint Commit'),
      phutil_tag(
        'a',
        array(
          'href' => $drequest->generateURI(
            array(
              'action' => 'commit',
              'commit' => $lint_commit,
            )),
        ),
        $drequest->getRepository()->formatCommitName($lint_commit)));

    $view->addProperty(
      pht('Total Messages'),
      pht('%s', new PhutilNumber($total)));

    return $view;
  }


}
