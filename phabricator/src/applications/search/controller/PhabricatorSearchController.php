<?php

/*
 * Copyright 2012 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * @group search
 */
final class PhabricatorSearchController
  extends PhabricatorSearchBaseController {

  private $key;

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
          $response = PhabricatorJumpNavHandler::jumpPostResponse($query_str);
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
                  PhabricatorPHIDConstants::PHID_TYPE_DREV);
                break;
              case PhabricatorSearchScope::SCOPE_OPEN_TASKS:
                $query->setParameter('open', 1);
                $query->setParameter(
                  'type',
                  PhabricatorPHIDConstants::PHID_TYPE_TASK);
                break;
              case PhabricatorSearchScope::SCOPE_WIKI:
                $query->setParameter(
                  'type',
                  PhabricatorPHIDConstants::PHID_TYPE_WIKI);
                break;
              case PhabricatorSearchScope::SCOPE_COMMITS:
                $query->setParameter(
                  'type',
                  PhabricatorPHIDConstants::PHID_TYPE_CMIT);
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
      $query->getParameter('project', array())
    );

    $handles = id(new PhabricatorObjectHandleData($phids))
      ->loadHandles();

    $author_value = array_select_keys(
      $handles,
      $query->getParameter('author', array()));
    $author_value = mpull($author_value, 'getFullName', 'getPHID');

    $owner_value = array_select_keys(
      $handles,
      $query->getParameter('owner', array()));
    $owner_value = mpull($owner_value, 'getFullName', 'getPHID');

    $project_value = array_select_keys(
      $handles,
      $query->getParameter('project', array()));
    $project_value = mpull($project_value, 'getFullName', 'getPHID');

    $search_form = new AphrontFormView();
    $search_form
      ->setUser($user)
      ->setAction('/search/')
      ->appendChild(
        phutil_render_tag(
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
          ->setName('project')
          ->setLabel('Project')
          ->setDatasource('/typeahead/common/projects/')
          ->setValue($project_value))
      ->appendChild(
        id(new AphrontFormSubmitControl())
          ->setValue('Search'));

    $search_panel = new AphrontPanelView();
    $search_panel->setHeader('Search Phabricator');
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

      if (!$request->getInt('page')) {
        $jump = null;
        $query_str = $query->getQuery();
        $match = null;
        if (preg_match('/^r([A-Z]+)(\S*)$/', $query_str, $match)) {
          $repository = id(new PhabricatorRepository())
            ->loadOneWhere('callsign = %s', $match[1]);
          if ($match[2] == '') {
            $jump = $repository;
          } else if ($repository) {
            $jump = id(new PhabricatorRepositoryCommit())->loadOneWhere(
              'repositoryID = %d AND commitIdentifier = %s',
              $repository->getID(),
              $match[2]);
            if (!$jump) {
              try {
                $jump = id(new PhabricatorRepositoryCommit())->loadOneWhere(
                  'repositoryID = %d AND commitIdentifier LIKE %>',
                  $repository->getID(),
                  $match[2]);
              } catch (AphrontQueryCountException $ex) {
                // Ambiguous, no jump.
              }
            }
          }
        } else if (preg_match('/^d(\d+)$/i', $query_str, $match)) {
          $jump = id(new DifferentialRevision())->load($match[1]);
        } else if (preg_match('/^t(\d+)$/i', $query_str, $match)) {
          $jump = id(new ManiphestTask())->load($match[1]);
        }
        if ($jump) {
          array_unshift($results, $jump->getPHID());
        }
      }

      if ($results) {

        $loader = new PhabricatorObjectHandleData($results);
        $handles = $loader->loadHandles();
        $objects = $loader->loadObjects();
        $results = array();
        foreach ($handles as $phid => $handle) {
          $view = new PhabricatorSearchResultView();
          $view->setHandle($handle);
          $view->setQuery($query);
          $view->setObject(idx($objects, $phid));
          $results[] = $view->render();
        }
        $results =
          '<div class="phabricator-search-result-list">'.
            implode("\n", $results).
            '<div class="search-results-pager">'.
              $pager->render().
            '</div>'.
          '</div>';
      } else {
        $results =
          '<div class="phabricator-search-result-list">'.
            '<p class="phabricator-search-no-results">No search results.</p>'.
          '</div>';
      }
    } else {
      $results = null;
    }

    return $this->buildStandardPageResponse(
      array(
        $search_panel,
        $results,
      ),
      array(
        'title' => 'Search Results',
      ));
  }


}
