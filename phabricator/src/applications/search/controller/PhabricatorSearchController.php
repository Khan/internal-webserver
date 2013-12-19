<?php

/**
 * @group search
 */
final class PhabricatorSearchController
  extends PhabricatorSearchBaseController {

  private $key;

  public function shouldAllowPublic() {
    return true;
  }

  public function willProcessRequest(array $data) {
    $this->key = idx($data, 'key');
  }

  public function processRequest() {
    $request = $this->getRequest();
    $user = $request->getUser();

    if ($this->key) {
      $query = id(new PhabricatorSearchQuery())->loadOneWhere(
        'queryKey = %s',
        $this->key);
      if (!$query) {
        return new Aphront404Response();
      }
    } else {
      $query = new PhabricatorSearchQuery();

      if ($request->isFormPost()) {
        $query_str = $request->getStr('query');

        $pref_jump = PhabricatorUserPreferences::PREFERENCE_SEARCHBAR_JUMP;
        if ($request->getStr('jump') != 'no' &&
            $user && $user->loadPreferences()->getPreference($pref_jump, 1)) {
          $response = PhabricatorJumpNavHandler::getJumpResponse(
            $user,
            $query_str);
        } else {
          $response = null;
        }
        if ($response) {
          return $response;
        } else {
          $query->setQuery($query_str);

          if ($request->getStr('scope')) {
            switch ($request->getStr('scope')) {
              case PhabricatorSearchScope::SCOPE_OPEN_REVISIONS:
                $query->setParameter('open', 1);
                $query->setParameter(
                  'type',
                  DifferentialPHIDTypeRevision::TYPECONST);
                break;
              case PhabricatorSearchScope::SCOPE_OPEN_TASKS:
                $query->setParameter('open', 1);
                $query->setParameter(
                  'type',
                  ManiphestPHIDTypeTask::TYPECONST);
                break;
              case PhabricatorSearchScope::SCOPE_WIKI:
                $query->setParameter(
                  'type',
                  PhrictionPHIDTypeDocument::TYPECONST);
                break;
              case PhabricatorSearchScope::SCOPE_COMMITS:
                $query->setParameter(
                  'type',
                  PhabricatorRepositoryPHIDTypeCommit::TYPECONST);
                break;
              default:
                break;
            }
          } else {
            if (strlen($request->getStr('type'))) {
              $query->setParameter('type', $request->getStr('type'));
            }

            if ($request->getArr('author')) {
              $query->setParameter('author', $request->getArr('author'));
            }

            if ($request->getArr('owner')) {
              $query->setParameter('owner', $request->getArr('owner'));
            }

            if ($request->getArr('subscribers')) {
              $query->setParameter('subscribers',
                                   $request->getArr('subscribers'));
            }

            if ($request->getInt('open')) {
              $query->setParameter('open', $request->getInt('open'));
            }

            if ($request->getArr('project')) {
              $query->setParameter('project', $request->getArr('project'));
            }
          }

          $query->save();
          return id(new AphrontRedirectResponse())
            ->setURI('/search/'.$query->getQueryKey().'/');
        }
      }
    }

    $options = array(
      '' => 'All Documents',
    ) + PhabricatorSearchAbstractDocument::getSupportedTypes();

    $status_options = array(
      0 => 'Open and Closed Documents',
      1 => 'Open Documents',
    );

    $phids = array_merge(
      $query->getParameter('author', array()),
      $query->getParameter('owner', array()),
      $query->getParameter('subscribers', array()),
      $query->getParameter('project', array()));

    $handles = $this->loadViewerHandles($phids);

    $author_value = array_select_keys(
      $handles,
      $query->getParameter('author', array()));

    $owner_value = array_select_keys(
      $handles,
      $query->getParameter('owner', array()));

    $subscribers_value = array_select_keys(
      $handles,
      $query->getParameter('subscribers', array()));

    $project_value = array_select_keys(
      $handles,
      $query->getParameter('project', array()));

    $search_form = new AphrontFormView();
    $search_form
      ->setUser($user)
      ->setAction('/search/')
      ->appendChild(
        phutil_tag(
          'input',
          array(
            'type' => 'hidden',
            'name' => 'jump',
            'value' => 'no',
          )))
      ->appendChild(
        id(new AphrontFormTextControl())
          ->setLabel('Search')
          ->setName('query')
          ->setValue($query->getQuery()))
      ->appendChild(
        id(new AphrontFormSelectControl())
          ->setLabel('Document Type')
          ->setName('type')
          ->setOptions($options)
          ->setValue($query->getParameter('type')))
      ->appendChild(
        id(new AphrontFormSelectControl())
          ->setLabel('Document Status')
          ->setName('open')
          ->setOptions($status_options)
          ->setValue($query->getParameter('open')))
      ->appendChild(
        id(new AphrontFormTokenizerControl())
          ->setName('author')
          ->setLabel('Author')
          ->setDatasource('/typeahead/common/users/')
          ->setValue($author_value))
      ->appendChild(
        id(new AphrontFormTokenizerControl())
          ->setName('owner')
          ->setLabel('Owner')
          ->setDatasource('/typeahead/common/searchowner/')
          ->setValue($owner_value)
          ->setCaption(
            'Tip: search for "Up For Grabs" to find unowned documents.'))
      ->appendChild(
        id(new AphrontFormTokenizerControl())
          ->setName('subscribers')
          ->setLabel('Subscribers')
          ->setDatasource('/typeahead/common/users/')
          ->setValue($subscribers_value))
      ->appendChild(
        id(new AphrontFormTokenizerControl())
          ->setName('project')
          ->setLabel('Project')
          ->setDatasource('/typeahead/common/projects/')
          ->setValue($project_value))
      ->appendChild(
        id(new AphrontFormSubmitControl())
          ->setValue('Search'));

    $search_panel = new AphrontListFilterView();
    $search_panel->appendChild($search_form);

    require_celerity_resource('phabricator-search-results-css');

    if ($query->getID()) {

      $limit = 20;

      $pager = new AphrontPagerView();
      $pager->setURI($request->getRequestURI(), 'page');
      $pager->setPageSize($limit);
      $pager->setOffset($request->getInt('page'));

      $query->setParameter('limit', $limit + 1);
      $query->setParameter('offset', $pager->getOffset());

      $engine = PhabricatorSearchEngineSelector::newSelector()->newEngine();
      $results = $engine->executeSearch($query);
      $results = $pager->sliceResults($results);

      // If there are any objects which match the query by name, and we're
      // not paging through the results, prefix the results with the named
      // objects.
      if (!$request->getInt('page')) {
        $named = id(new PhabricatorObjectQuery())
          ->setViewer($user)
          ->withNames(array($query->getQuery()))
          ->execute();
        if ($named) {
          $results = array_merge(array_keys($named), $results);
        }
      }

      if ($results) {
        $handles = id(new PhabricatorHandleQuery())
          ->setViewer($user)
          ->withPHIDs($results)
          ->execute();
        $objects = id(new PhabricatorObjectQuery())
          ->setViewer($user)
          ->withPHIDs($results)
          ->execute();
        $results = array();
        foreach ($handles as $phid => $handle) {
          $view = id(new PhabricatorSearchResultView())
            ->setHandle($handle)
            ->setQuery($query)
            ->setObject(idx($objects, $phid));
          $results[] = $view->render();
        }

        $results = phutil_tag_div('phabricator-search-result-list', array(
          phutil_implode_html("\n", $results),
          phutil_tag_div('search-results-pager', $pager->render()),
        ));
      } else {
        $results = phutil_tag_div(
          'phabricator-search-result-list',
          phutil_tag(
            'p',
            array('class' => 'phabricator-search-no-results'),
            pht('No search results.')));
      }
      $results = id(new PHUIBoxView())
        ->addMargin(PHUI::MARGIN_LARGE)
        ->addPadding(PHUI::PADDING_LARGE)
        ->setShadow(true)
        ->appendChild($results)
        ->addClass('phabricator-search-result-box');
    } else {
      $results = null;
    }

    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addTextCrumb(pht('Search'));

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $search_panel,
        $results,
      ),
      array(
        'title' => pht('Search Results'),
        'device' => true,
      ));
  }


}
