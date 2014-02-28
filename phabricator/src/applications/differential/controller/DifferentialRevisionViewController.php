<?php

final class DifferentialRevisionViewController extends DifferentialController {

  private $revisionID;

  public function shouldAllowPublic() {
    return true;
  }

  public function willProcessRequest(array $data) {
    $this->revisionID = $data['id'];
  }

  public function processRequest() {

    $request = $this->getRequest();
    $user = $request->getUser();
    $viewer_is_anonymous = !$user->isLoggedIn();

    $revision = id(new DifferentialRevisionQuery())
      ->withIDs(array($this->revisionID))
      ->setViewer($request->getUser())
      ->needRelationships(true)
      ->needReviewerStatus(true)
      ->needReviewerAuthority(true)
      ->executeOne();

    if (!$revision) {
      return new Aphront404Response();
    }

    $diffs = id(new DifferentialDiffQuery())
      ->setViewer($request->getUser())
      ->withRevisionIDs(array($this->revisionID))
      ->execute();
    $diffs = array_reverse($diffs, $preserve_keys = true);

    if (!$diffs) {
      throw new Exception(
        "This revision has no diffs. Something has gone quite wrong.");
    }

    $revision->attachActiveDiff(last($diffs));

    $diff_vs = $request->getInt('vs');

    $target_id = $request->getInt('id');
    $target = idx($diffs, $target_id, end($diffs));

    $target_manual = $target;
    if (!$target_id) {
      foreach ($diffs as $diff) {
        if ($diff->getCreationMethod() != 'commit') {
          $target_manual = $diff;
        }
      }
    }

    if (empty($diffs[$diff_vs])) {
      $diff_vs = null;
    }

    $arc_project = $target->loadArcanistProject();
    $repository = ($arc_project ? $arc_project->loadRepository() : null);

    list($changesets, $vs_map, $vs_changesets, $rendering_references) =
      $this->loadChangesetsAndVsMap(
        $target,
        idx($diffs, $diff_vs),
        $repository);

    if ($request->getExists('download')) {
      return $this->buildRawDiffResponse(
        $revision,
        $changesets,
        $vs_changesets,
        $vs_map,
        $repository);
    }

    $props = id(new DifferentialDiffProperty())->loadAllWhere(
      'diffID = %d',
      $target_manual->getID());
    $props = mpull($props, 'getData', 'getName');

    $comments = $revision->loadComments();

    $all_changesets = $changesets;
    $inlines = $this->loadInlineComments(
      $revision,
      $all_changesets);

    $object_phids = array_merge(
      $revision->getReviewers(),
      $revision->getCCPHIDs(),
      $revision->loadCommitPHIDs(),
      array(
        $revision->getAuthorPHID(),
        $user->getPHID(),
      ),
      mpull($comments, 'getAuthorPHID'));

    foreach ($comments as $comment) {
      foreach ($comment->getRequiredHandlePHIDs() as $phid) {
        $object_phids[] = $phid;
      }
    }

    foreach ($revision->getAttached() as $type => $phids) {
      foreach ($phids as $phid => $info) {
        $object_phids[] = $phid;
      }
    }

    $handles = $this->loadViewerHandles($object_phids);

    $request_uri = $request->getRequestURI();

    $limit = 100;
    $large = $request->getStr('large');
    if (count($changesets) > $limit && !$large) {
      $count = count($changesets);
      $warning = new AphrontErrorView();
      $warning->setTitle('Very Large Diff');
      $warning->setSeverity(AphrontErrorView::SEVERITY_WARNING);
      $warning->appendChild(hsprintf(
        '%s <strong>%s</strong>',
        pht(
          'This diff is very large and affects %s files. Load each file '.
            'individually.',
          new PhutilNumber($count)),
        phutil_tag(
          'a',
          array(
            'href' => $request_uri
              ->alter('large', 'true')
              ->setFragment('toc'),
          ),
          pht('Show All Files Inline'))));
      $warning = $warning->render();

      $my_inlines = id(new DifferentialInlineCommentQuery())
        ->withDraftComments($user->getPHID(), $this->revisionID)
        ->execute();

      $visible_changesets = array();
      foreach ($inlines + $my_inlines as $inline) {
        $changeset_id = $inline->getChangesetID();
        if (isset($changesets[$changeset_id])) {
          $visible_changesets[$changeset_id] = $changesets[$changeset_id];
        }
      }

      if (!empty($props['arc:lint'])) {
        $changeset_paths = mpull($changesets, null, 'getFilename');
        foreach ($props['arc:lint'] as $lint) {
          $changeset = idx($changeset_paths, $lint['path']);
          if ($changeset) {
            $visible_changesets[$changeset->getID()] = $changeset;
          }
        }
      }

    } else {
      $warning = null;
      $visible_changesets = $changesets;
    }

    $field_list = PhabricatorCustomField::getObjectFields(
      $revision,
      PhabricatorCustomField::ROLE_VIEW);

    $field_list->setViewer($user);
    $field_list->readFieldsFromStorage($revision);


    // TODO: This should be in a DiffQuery or similar.
    $need_props = array();
    foreach ($field_list->getFields() as $field) {
      foreach ($field->getRequiredDiffPropertiesForRevisionView() as $prop) {
        $need_props[$prop] = $prop;
      }
    }

    if ($need_props) {
      $prop_diff = $revision->getActiveDiff();
      $load_props = id(new DifferentialDiffProperty())->loadAllWhere(
        'diffID = %d AND name IN (%Ls)',
        $prop_diff->getID(),
        $need_props);
      $load_props = mpull($load_props, 'getData', 'getName');
      foreach ($need_props as $need) {
        $prop_diff->attachProperty($need, idx($load_props, $need));
      }
    }


    $revision_detail = id(new DifferentialRevisionDetailView())
      ->setUser($user)
      ->setRevision($revision)
      ->setDiff(end($diffs))
      ->setCustomFields($field_list)
      ->setURI($request->getRequestURI());

    $actions = $this->getRevisionActions($revision);

    $whitespace = $request->getStr(
      'whitespace',
      DifferentialChangesetParser::WHITESPACE_IGNORE_ALL);

    if ($arc_project) {
      list($symbol_indexes, $project_phids) = $this->buildSymbolIndexes(
        $arc_project,
        $visible_changesets);
    } else {
      $symbol_indexes = array();
      $project_phids = null;
    }

    $revision_detail->setActions($actions);
    $revision_detail->setUser($user);

    $revision_detail_box = $revision_detail->render();

    $revision_warnings = $this->buildRevisionWarnings($revision, $handles);
    if ($revision_warnings) {
      $revision_detail_box->setErrorView($revision_warnings);
    }

    $comment_view = $this->buildTransactions(
      $revision,
      $diff_vs ? $diffs[$diff_vs] : $target,
      $target,
      $all_changesets);

    $wrap_id = celerity_generate_unique_node_id();
    $comment_view = phutil_tag(
      'div',
      array(
        'id' => $wrap_id,
      ),
      $comment_view);

    if ($arc_project) {
      Javelin::initBehavior(
        'repository-crossreference',
        array(
          'section' => $wrap_id,
          'projects' => $project_phids,
        ));
    }

    $changeset_view = new DifferentialChangesetListView();
    $changeset_view->setChangesets($changesets);
    $changeset_view->setVisibleChangesets($visible_changesets);

    if (!$viewer_is_anonymous) {
      $changeset_view->setInlineCommentControllerURI(
        '/differential/comment/inline/edit/'.$revision->getID().'/');
    }

    $changeset_view->setStandaloneURI('/differential/changeset/');
    $changeset_view->setRawFileURIs(
      '/differential/changeset/?view=old',
      '/differential/changeset/?view=new');

    $changeset_view->setUser($user);
    $changeset_view->setDiff($target);
    $changeset_view->setRenderingReferences($rendering_references);
    $changeset_view->setVsMap($vs_map);
    $changeset_view->setWhitespace($whitespace);
    if ($repository) {
      $changeset_view->setRepository($repository);
    }
    $changeset_view->setSymbolIndexes($symbol_indexes);
    $changeset_view->setTitle('Diff '.$target->getID());

    $diff_history = new DifferentialRevisionUpdateHistoryView();
    $diff_history->setDiffs($diffs);
    $diff_history->setSelectedVersusDiffID($diff_vs);
    $diff_history->setSelectedDiffID($target->getID());
    $diff_history->setSelectedWhitespace($whitespace);
    $diff_history->setUser($user);

    $local_view = new DifferentialLocalCommitsView();
    $local_view->setUser($user);
    $local_view->setLocalCommits(idx($props, 'local:commits'));

    if ($repository) {
      $other_revisions = $this->loadOtherRevisions(
        $changesets,
        $target,
        $repository);
    } else {
      $other_revisions = array();
    }

    $other_view = null;
    if ($other_revisions) {
      $other_view = $this->renderOtherRevisions($other_revisions);
    }

    $toc_view = new DifferentialDiffTableOfContentsView();
    $toc_view->setChangesets($changesets);
    $toc_view->setVisibleChangesets($visible_changesets);
    $toc_view->setRenderingReferences($rendering_references);
    $toc_view->setUnitTestData(idx($props, 'arc:unit', array()));
    if ($repository) {
      $toc_view->setRepository($repository);
    }
    $toc_view->setDiff($target);
    $toc_view->setUser($user);
    $toc_view->setRevisionID($revision->getID());
    $toc_view->setWhitespace($whitespace);

    $comment_form = null;
    if (!$viewer_is_anonymous) {
      $draft = id(new PhabricatorDraft())->loadOneWhere(
        'authorPHID = %s AND draftKey = %s',
        $user->getPHID(),
        'differential-comment-'.$revision->getID());

      $reviewers = array();
      $ccs = array();
      if ($draft) {
        $reviewers = idx($draft->getMetadata(), 'reviewers', array());
        $ccs = idx($draft->getMetadata(), 'ccs', array());
        if ($reviewers || $ccs) {
          $handles = $this->loadViewerHandles(array_merge($reviewers, $ccs));
          $reviewers = array_select_keys($handles, $reviewers);
          $ccs = array_select_keys($handles, $ccs);
        }
      }

      $comment_form = new DifferentialAddCommentView();
      $comment_form->setRevision($revision);

      // TODO: Restore the ability for fields to add accept warnings.

      $comment_form->setActions($this->getRevisionCommentActions($revision));
      $action_uri = $this->getApplicationURI(
        'comment/save/'.$revision->getID().'/');

      $comment_form->setActionURI($action_uri);
      $comment_form->setUser($user);
      $comment_form->setDraft($draft);
      $comment_form->setReviewers(mpull($reviewers, 'getFullName', 'getPHID'));
      $comment_form->setCCs(mpull($ccs, 'getFullName', 'getPHID'));

      // TODO: This just makes the "Z" key work. Generalize this and remove
      // it at some point.
      $comment_form = phutil_tag(
        'div',
        array(
          'class' => 'differential-add-comment-panel',
        ),
        $comment_form);
    }

    $pane_id = celerity_generate_unique_node_id();
    Javelin::initBehavior(
      'differential-keyboard-navigation',
      array(
        'haunt' => $pane_id,
      ));
    Javelin::initBehavior('differential-user-select');

    $page_pane = id(new DifferentialPrimaryPaneView())
      ->setID($pane_id)
      ->appendChild(array(
        $comment_view,
        $diff_history,
        $warning,
        $local_view,
        $toc_view,
        $other_view,
        $changeset_view,
      ));
    if ($comment_form) {
      $page_pane->appendChild($comment_form);
    } else {
      // TODO: For now, just use this to get "Login to Comment".
      $page_pane->appendChild(
        id(new PhabricatorApplicationTransactionCommentView())
          ->setUser($user)
          ->setRequestURI($request->getRequestURI()));
    }


    $object_id = 'D'.$revision->getID();

    $top_anchor = id(new PhabricatorAnchorView())
      ->setAnchorName('top')
      ->setNavigationMarker(true);

    $content = array(
      $top_anchor,
      $revision_detail_box,
      $page_pane,
    );

    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addTextCrumb($object_id, '/'.$object_id);

    $prefs = $user->loadPreferences();

    $pref_filetree = PhabricatorUserPreferences::PREFERENCE_DIFF_FILETREE;
    if ($prefs->getPreference($pref_filetree)) {
      $collapsed = $prefs->getPreference(
        PhabricatorUserPreferences::PREFERENCE_NAV_COLLAPSED,
        false);

      $nav = id(new DifferentialChangesetFileTreeSideNavBuilder())
        ->setAnchorName('top')
        ->setTitle('D'.$revision->getID())
        ->setBaseURI(new PhutilURI('/D'.$revision->getID()))
        ->setCollapsed((bool)$collapsed)
        ->build($changesets);
      $nav->appendChild($content);
      $nav->setCrumbs($crumbs);
      $content = $nav;
    } else {
      array_unshift($content, $crumbs);
    }

    return $this->buildApplicationPage(
      $content,
      array(
        'title' => $object_id.' '.$revision->getTitle(),
        'pageObjects' => array($revision->getPHID()),
      ));
  }

  private function getRevisionActions(DifferentialRevision $revision) {
    $viewer = $this->getRequest()->getUser();
    $revision_id = $revision->getID();
    $revision_phid = $revision->getPHID();

    $can_edit = PhabricatorPolicyFilter::hasCapability(
      $viewer,
      $revision,
      PhabricatorPolicyCapability::CAN_EDIT);

    $actions = array();

    $actions[] = id(new PhabricatorActionView())
      ->setIcon('edit')
      ->setHref("/differential/revision/edit/{$revision_id}/")
      ->setName(pht('Edit Revision'))
      ->setDisabled(!$can_edit)
      ->setWorkflow(!$can_edit);

    $this->requireResource('phabricator-object-selector-css');
    $this->requireResource('javelin-behavior-phabricator-object-selector');

    $actions[] = id(new PhabricatorActionView())
      ->setIcon('link')
      ->setName(pht('Edit Dependencies'))
      ->setHref("/search/attach/{$revision_phid}/DREV/dependencies/")
      ->setWorkflow(true)
      ->setDisabled(!$can_edit);

    $maniphest = 'PhabricatorApplicationManiphest';
    if (PhabricatorApplication::isClassInstalled($maniphest)) {
      $actions[] = id(new PhabricatorActionView())
        ->setIcon('attach')
        ->setName(pht('Edit Maniphest Tasks'))
        ->setHref("/search/attach/{$revision_phid}/TASK/")
        ->setWorkflow(true)
        ->setDisabled(!$can_edit);
    }

    $request_uri = $this->getRequest()->getRequestURI();
    $actions[] = id(new PhabricatorActionView())
      ->setIcon('download')
      ->setName(pht('Download Raw Diff'))
      ->setHref($request_uri->alter('download', 'true'));

    return $actions;
  }

  private function getRevisionCommentActions(DifferentialRevision $revision) {

    $actions = array(
      DifferentialAction::ACTION_COMMENT => true,
    );

    $viewer = $this->getRequest()->getUser();
    $viewer_phid = $viewer->getPHID();
    $viewer_is_owner = ($viewer_phid == $revision->getAuthorPHID());
    $viewer_is_reviewer = in_array($viewer_phid, $revision->getReviewers());
    $status = $revision->getStatus();

    $viewer_has_accepted = false;
    $viewer_has_rejected = false;
    $status_accepted = DifferentialReviewerStatus::STATUS_ACCEPTED;
    $status_rejected = DifferentialReviewerStatus::STATUS_REJECTED;
    foreach ($revision->getReviewerStatus() as $reviewer) {
      if ($reviewer->getReviewerPHID() == $viewer_phid) {
        if ($reviewer->getStatus() == $status_accepted) {
          $viewer_has_accepted = true;
        }
        if ($reviewer->getStatus() == $status_rejected) {
          $viewer_has_rejected = true;
        }
        break;
      }
    }

    $allow_self_accept = PhabricatorEnv::getEnvConfig(
      'differential.allow-self-accept');
    $always_allow_close = PhabricatorEnv::getEnvConfig(
      'differential.always-allow-close');
    $allow_reopen = PhabricatorEnv::getEnvConfig(
      'differential.allow-reopen');

    if ($viewer_is_owner) {
      switch ($status) {
        case ArcanistDifferentialRevisionStatus::NEEDS_REVIEW:
          $actions[DifferentialAction::ACTION_ACCEPT] = $allow_self_accept;
          $actions[DifferentialAction::ACTION_ABANDON] = true;
          $actions[DifferentialAction::ACTION_RETHINK] = true;
          break;
        case ArcanistDifferentialRevisionStatus::NEEDS_REVISION:
          $actions[DifferentialAction::ACTION_ACCEPT] = $allow_self_accept;
          $actions[DifferentialAction::ACTION_ABANDON] = true;
          $actions[DifferentialAction::ACTION_REQUEST] = true;
          break;
        case ArcanistDifferentialRevisionStatus::ACCEPTED:
          $actions[DifferentialAction::ACTION_ABANDON] = true;
          $actions[DifferentialAction::ACTION_REQUEST] = true;
          $actions[DifferentialAction::ACTION_RETHINK] = true;
          $actions[DifferentialAction::ACTION_CLOSE] = true;
          break;
        case ArcanistDifferentialRevisionStatus::CLOSED:
          break;
        case ArcanistDifferentialRevisionStatus::ABANDONED:
          $actions[DifferentialAction::ACTION_RECLAIM] = true;
          break;
      }
    } else {
      switch ($status) {
        case ArcanistDifferentialRevisionStatus::NEEDS_REVIEW:
          $actions[DifferentialAction::ACTION_ACCEPT] = true;
          $actions[DifferentialAction::ACTION_REJECT] = true;
          $actions[DifferentialAction::ACTION_RESIGN] = $viewer_is_reviewer;
          break;
        case ArcanistDifferentialRevisionStatus::NEEDS_REVISION:
          $actions[DifferentialAction::ACTION_ACCEPT] = true;
          $actions[DifferentialAction::ACTION_REJECT] = !$viewer_has_rejected;
          $actions[DifferentialAction::ACTION_RESIGN] = $viewer_is_reviewer;
          break;
        case ArcanistDifferentialRevisionStatus::ACCEPTED:
          $actions[DifferentialAction::ACTION_ACCEPT] = !$viewer_has_accepted;
          $actions[DifferentialAction::ACTION_REJECT] = true;
          $actions[DifferentialAction::ACTION_RESIGN] = $viewer_is_reviewer;
          break;
        case ArcanistDifferentialRevisionStatus::CLOSED:
        case ArcanistDifferentialRevisionStatus::ABANDONED:
          break;
      }
      if ($status != ArcanistDifferentialRevisionStatus::CLOSED) {
        $actions[DifferentialAction::ACTION_CLAIM] = true;
        $actions[DifferentialAction::ACTION_CLOSE] = $always_allow_close;
      }
    }

    $actions[DifferentialAction::ACTION_ADDREVIEWERS] = true;
    $actions[DifferentialAction::ACTION_ADDCCS] = true;
    $actions[DifferentialAction::ACTION_REOPEN] = $allow_reopen &&
      ($status == ArcanistDifferentialRevisionStatus::CLOSED);

    $actions = array_keys(array_filter($actions));
    $actions_dict = array();
    foreach ($actions as $action) {
      $actions_dict[$action] = DifferentialAction::getActionVerb($action);
    }

    return $actions_dict;
  }

  private function loadInlineComments(
    DifferentialRevision $revision,
    array &$changesets) {
    assert_instances_of($changesets, 'DifferentialChangeset');

    $inline_comments = array();

    $inline_comments = id(new DifferentialInlineCommentQuery())
      ->withRevisionIDs(array($revision->getID()))
      ->withNotDraft(true)
      ->execute();

    $load_changesets = array();
    foreach ($inline_comments as $inline) {
      $changeset_id = $inline->getChangesetID();
      if (isset($changesets[$changeset_id])) {
        continue;
      }
      $load_changesets[$changeset_id] = true;
    }

    $more_changesets = array();
    if ($load_changesets) {
      $changeset_ids = array_keys($load_changesets);
      $more_changesets += id(new DifferentialChangeset())
        ->loadAllWhere(
          'id IN (%Ld)',
          $changeset_ids);
    }

    if ($more_changesets) {
      $changesets += $more_changesets;
      $changesets = msort($changesets, 'getSortKey');
    }

    return $inline_comments;
  }

  private function loadChangesetsAndVsMap(
    DifferentialDiff $target,
    DifferentialDiff $diff_vs = null,
    PhabricatorRepository $repository = null) {

    $load_ids = array();
    if ($diff_vs) {
      $load_ids[] = $diff_vs->getID();
    }
    $load_ids[] = $target->getID();

    $raw_changesets = id(new DifferentialChangeset())
      ->loadAllWhere(
        'diffID IN (%Ld)',
        $load_ids);
    $changeset_groups = mgroup($raw_changesets, 'getDiffID');

    $changesets = idx($changeset_groups, $target->getID(), array());
    $changesets = mpull($changesets, null, 'getID');

    $refs          = array();
    $vs_map        = array();
    $vs_changesets = array();
    if ($diff_vs) {
      $vs_id                  = $diff_vs->getID();
      $vs_changesets_path_map = array();
      foreach (idx($changeset_groups, $vs_id, array()) as $changeset) {
        $path = $changeset->getAbsoluteRepositoryPath($repository, $diff_vs);
        $vs_changesets_path_map[$path] = $changeset;
        $vs_changesets[$changeset->getID()] = $changeset;
      }
      foreach ($changesets as $key => $changeset) {
        $path = $changeset->getAbsoluteRepositoryPath($repository, $target);
        if (isset($vs_changesets_path_map[$path])) {
          $vs_map[$changeset->getID()] =
            $vs_changesets_path_map[$path]->getID();
          $refs[$changeset->getID()] =
            $changeset->getID().'/'.$vs_changesets_path_map[$path]->getID();
          unset($vs_changesets_path_map[$path]);
        } else {
          $refs[$changeset->getID()] = $changeset->getID();
        }
      }
      foreach ($vs_changesets_path_map as $path => $changeset) {
        $changesets[$changeset->getID()] = $changeset;
        $vs_map[$changeset->getID()]     = -1;
        $refs[$changeset->getID()]       = $changeset->getID().'/-1';
      }
    } else {
      foreach ($changesets as $changeset) {
        $refs[$changeset->getID()] = $changeset->getID();
      }
    }

    $changesets = msort($changesets, 'getSortKey');

    return array($changesets, $vs_map, $vs_changesets, $refs);
  }

  private function buildSymbolIndexes(
    PhabricatorRepositoryArcanistProject $arc_project,
    array $visible_changesets) {
    assert_instances_of($visible_changesets, 'DifferentialChangeset');

    $engine = PhabricatorSyntaxHighlighter::newEngine();

    $langs = $arc_project->getSymbolIndexLanguages();
    if (!$langs) {
      return array(array(), array());
    }

    $symbol_indexes = array();

    $project_phids = array_merge(
      array($arc_project->getPHID()),
      nonempty($arc_project->getSymbolIndexProjects(), array()));

    $indexed_langs = array_fill_keys($langs, true);
    foreach ($visible_changesets as $key => $changeset) {
      $lang = $engine->getLanguageFromFilename($changeset->getFilename());
      if (isset($indexed_langs[$lang])) {
        $symbol_indexes[$key] = array(
          'lang'      => $lang,
          'projects'  => $project_phids,
        );
      }
    }

    return array($symbol_indexes, $project_phids);
  }

  private function loadOtherRevisions(
    array $changesets,
    DifferentialDiff $target,
    PhabricatorRepository $repository) {
    assert_instances_of($changesets, 'DifferentialChangeset');

    $paths = array();
    foreach ($changesets as $changeset) {
      $paths[] = $changeset->getAbsoluteRepositoryPath(
        $repository,
        $target);
    }

    if (!$paths) {
      return array();
    }

    $path_map = id(new DiffusionPathIDQuery($paths))->loadPathIDs();

    if (!$path_map) {
      return array();
    }

    $query = id(new DifferentialRevisionQuery())
      ->setViewer($this->getRequest()->getUser())
      ->withStatus(DifferentialRevisionQuery::STATUS_OPEN)
      ->setOrder(DifferentialRevisionQuery::ORDER_PATH_MODIFIED)
      ->setLimit(10)
      ->needFlags(true)
      ->needDrafts(true)
      ->needRelationships(true);

    foreach ($path_map as $path => $path_id) {
      $query->withPath($repository->getID(), $path_id);
    }

    $results = $query->execute();

    // Strip out *this* revision.
    foreach ($results as $key => $result) {
      if ($result->getID() == $this->revisionID) {
        unset($results[$key]);
      }
    }

    return $results;
  }

  private function renderOtherRevisions(array $revisions) {
    assert_instances_of($revisions, 'DifferentialRevision');

    $user = $this->getRequest()->getUser();

    $view = id(new DifferentialRevisionListView())
      ->setRevisions($revisions)
      ->setFields(DifferentialRevisionListView::getDefaultFields($user))
      ->setUser($user);

    $phids = $view->getRequiredHandlePHIDs();
    $handles = $this->loadViewerHandles($phids);
    $view->setHandles($handles);

    return id(new PHUIObjectBoxView())
      ->setHeaderText(pht('Open Revisions Affecting These Files'))
      ->appendChild($view);
  }


  /**
   * Note this code is somewhat similar to the buildPatch method in
   * @{class:DifferentialReviewRequestMail}.
   *
   * @return @{class:AphrontRedirectResponse}
   */
  private function buildRawDiffResponse(
    DifferentialRevision $revision,
    array $changesets,
    array $vs_changesets,
    array $vs_map,
    PhabricatorRepository $repository = null) {

    assert_instances_of($changesets,    'DifferentialChangeset');
    assert_instances_of($vs_changesets, 'DifferentialChangeset');

    $viewer = $this->getRequest()->getUser();

    foreach ($changesets as $changeset) {
      $changeset->attachHunks($changeset->loadHunks());
    }

    $diff = new DifferentialDiff();
    $diff->attachChangesets($changesets);
    $raw_changes = $diff->buildChangesList();
    $changes = array();
    foreach ($raw_changes as $changedict) {
      $changes[] = ArcanistDiffChange::newFromDictionary($changedict);
    }

    $loader = id(new PhabricatorFileBundleLoader())
      ->setViewer($viewer);

    $bundle = ArcanistBundle::newFromChanges($changes);
    $bundle->setLoadFileDataCallback(array($loader, 'loadFileData'));

    $vcs = $repository ? $repository->getVersionControlSystem() : null;
    switch ($vcs) {
      case PhabricatorRepositoryType::REPOSITORY_TYPE_GIT:
      case PhabricatorRepositoryType::REPOSITORY_TYPE_MERCURIAL:
        $raw_diff = $bundle->toGitPatch();
        break;
      case PhabricatorRepositoryType::REPOSITORY_TYPE_SVN:
      default:
        $raw_diff = $bundle->toUnifiedDiff();
        break;
    }

    $request_uri = $this->getRequest()->getRequestURI();

    // this ends up being something like
    //   D123.diff
    // or the verbose
    //   D123.vs123.id123.whitespaceignore-all.diff
    // lame but nice to include these options
    $file_name = ltrim($request_uri->getPath(), '/').'.';
    foreach ($request_uri->getQueryParams() as $key => $value) {
      if ($key == 'download') {
        continue;
      }
      $file_name .= $key.$value.'.';
    }
    $file_name .= 'diff';

    $file = PhabricatorFile::buildFromFileDataOrHash(
      $raw_diff,
      array(
        'name' => $file_name,
        'ttl' => (60 * 60 * 24),
        'viewPolicy' => PhabricatorPolicies::POLICY_NOONE,
      ));

    $unguarded = AphrontWriteGuard::beginScopedUnguardedWrites();
      $file->attachToObject(
        $this->getRequest()->getUser(),
        $revision->getPHID());
    unset($unguarded);

    return id(new AphrontRedirectResponse())->setURI($file->getBestURI());

  }

  private function buildTransactions(
    DifferentialRevision $revision,
    DifferentialDiff $left_diff,
    DifferentialDiff $right_diff,
    array $changesets) {
    $viewer = $this->getRequest()->getUser();

    $xactions = id(new DifferentialTransactionQuery())
      ->setViewer($viewer)
      ->withObjectPHIDs(array($revision->getPHID()))
      ->needComments(true)
      ->execute();

    $timeline = id(new DifferentialTransactionView())
      ->setUser($viewer)
      ->setObjectPHID($revision->getPHID())
      ->setChangesets($changesets)
      ->setRevision($revision)
      ->setLeftDiff($left_diff)
      ->setRightDiff($right_diff)
      ->setTransactions($xactions);

    // TODO: Make this work and restore edit links. We need to copy
    // `revisionPHID` to the new version of the comment. This should be simple,
    // but can happen post-merge. See T2222.
    $timeline->setShowEditActions(false);

    return $timeline;
  }

  private function buildRevisionWarnings(
    DifferentialRevision $revision,
    array $handles) {

    $status_needs_review = ArcanistDifferentialRevisionStatus::NEEDS_REVIEW;
    if ($revision->getStatus() != $status_needs_review) {
      return;
    }

    foreach ($revision->getReviewers() as $reviewer) {
      if (!$handles[$reviewer]->isDisabled()) {
        return;
      }
    }

    $warnings = array();
    if ($revision->getReviewers()) {
      $warnings[] = pht(
        'This revision needs review, but all specified reviewers are '.
        'disabled or inactive.');
    } else {
      $warnings[] = pht(
        'This revision needs review, but there are no reviewers specified.');
    }

    return id(new AphrontErrorView())
      ->setSeverity(AphrontErrorView::SEVERITY_WARNING)
      ->setErrors($warnings);
  }

}
