<?php

final class ReleephBranchCreateController extends ReleephProjectController {

  public function processRequest() {
    $releeph_project = $this->getReleephProject();

    $request = $this->getRequest();

    $cut_point = $request->getStr('cutPoint');
    $symbolic_name = $request->getStr('symbolicName');

    if (!$cut_point) {
      $repository = $releeph_project->loadPhabricatorRepository();
      switch ($repository->getVersionControlSystem()) {
        case PhabricatorRepositoryType::REPOSITORY_TYPE_SVN:
          break;

        case PhabricatorRepositoryType::REPOSITORY_TYPE_GIT:
          $cut_point = $releeph_project->getTrunkBranch();
          break;
      }
    }

    $e_cut = true;
    $errors = array();

    $branch_date_control = id(new AphrontFormDateControl())
      ->setUser($request->getUser())
      ->setName('templateDate')
      ->setLabel(pht('Date'))
      ->setCaption(pht('The date used for filling out the branch template.'))
      ->setInitialTime(AphrontFormDateControl::TIME_START_OF_DAY);
    $branch_date = $branch_date_control->readValueFromRequest($request);

    if ($request->isFormPost()) {
      $cut_commit = null;
      if (!$cut_point) {
        $e_cut = pht('Required');
        $errors[] = pht('You must give a branch cut point');
      } else {
        try {
          $finder = id(new ReleephCommitFinder())
            ->setUser($request->getUser())
            ->setReleephProject($releeph_project);
          $cut_commit = $finder->fromPartial($cut_point);
        } catch (Exception $e) {
          $e_cut = pht('Invalid');
          $errors[] = $e->getMessage();
        }
      }

      if (!$errors) {
        $branch = id(new ReleephBranchEditor())
          ->setReleephProject($releeph_project)
          ->setActor($request->getUser())
          ->newBranchFromCommit(
            $cut_commit,
            $branch_date,
            $symbolic_name);

        return id(new AphrontRedirectResponse())
          ->setURI($branch->getURI());
      }
    }

    $error_view = array();
    if ($errors) {
      $error_view = new AphrontErrorView();
      $error_view->setErrors($errors);
      $error_view->setTitle(pht('Form Errors'));
    }

    $form = id(new AphrontFormView())
      ->setUser($request->getUser())
      ->appendChild(
        id(new AphrontFormTextControl())
          ->setLabel(pht('Symbolic Name'))
          ->setName('symbolicName')
          ->setValue($symbolic_name)
          ->setCaption(pht('Mutable alternate name, for easy reference, '.
              '(e.g. "LATEST")')))
      ->appendChild(
        id(new AphrontFormTextControl())
          ->setLabel(pht('Cut point'))
          ->setName('cutPoint')
          ->setValue($cut_point)
          ->setError($e_cut)
          ->setCaption(
              pht('A commit ID for your repo type, or a '.
              'Diffusion ID like "rE123"')))
      ->appendChild($branch_date_control)
      ->appendChild(
        id(new AphrontFormSubmitControl())
          ->setValue(pht('Cut Branch'))
          ->addCancelButton($releeph_project->getURI()));

    $panel = id(new AphrontPanelView())
      ->appendChild($form)
      ->setHeader(pht('Cut Branch'))
      ->setWidth(AphrontPanelView::WIDTH_FORM);

    return $this->buildStandardPageResponse(
      array($error_view, $panel),
      array('title' => pht('Cut new branch')));
  }
}
