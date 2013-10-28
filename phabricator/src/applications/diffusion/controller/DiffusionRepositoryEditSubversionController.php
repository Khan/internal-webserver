<?php

final class DiffusionRepositoryEditSubversionController
  extends DiffusionRepositoryEditController {

  public function processRequest() {
    $request = $this->getRequest();
    $viewer = $request->getUser();
    $drequest = $this->diffusionRequest;
    $repository = $drequest->getRepository();

    $repository = id(new PhabricatorRepositoryQuery())
      ->setViewer($viewer)
      ->requireCapabilities(
        array(
          PhabricatorPolicyCapability::CAN_VIEW,
          PhabricatorPolicyCapability::CAN_EDIT,
        ))
      ->withIDs(array($repository->getID()))
      ->executeOne();

    if (!$repository) {
      return new Aphront404Response();
    }

    switch ($repository->getVersionControlSystem()) {
      case PhabricatorRepositoryType::REPOSITORY_TYPE_GIT:
      case PhabricatorRepositoryType::REPOSITORY_TYPE_MERCURIAL:
        throw new Exception(
          pht('Git and Mercurial do not support editing SVN properties!'));
      case PhabricatorRepositoryType::REPOSITORY_TYPE_SVN:
        break;
      default:
        throw new Exception(
          pht('Repository has unknown version control system!'));
    }

    $edit_uri = $this->getRepositoryControllerURI($repository, 'edit/');

    $v_subpath = $repository->getHumanReadableDetail('svn-subpath');
    $v_uuid = $repository->getUUID();

    if ($request->isFormPost()) {
      $v_subpath = $request->getStr('subpath');
      $v_uuid = $request->getStr('uuid');

      $xactions = array();
      $template = id(new PhabricatorRepositoryTransaction());

      $type_subpath = PhabricatorRepositoryTransaction::TYPE_SVN_SUBPATH;
      $type_uuid = PhabricatorRepositoryTransaction::TYPE_UUID;

      $xactions[] = id(clone $template)
        ->setTransactionType($type_subpath)
        ->setNewValue($v_subpath);

      $xactions[] = id(clone $template)
        ->setTransactionType($type_uuid)
        ->setNewValue($v_uuid);

      id(new PhabricatorRepositoryEditor())
        ->setContinueOnNoEffect(true)
        ->setContentSourceFromRequest($request)
        ->setActor($viewer)
        ->applyTransactions($repository, $xactions);

      return id(new AphrontRedirectResponse())->setURI($edit_uri);
    }

    $content = array();

    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addCrumb(
      id(new PhabricatorCrumbView())
        ->setName(pht('Edit Subversion Info')));

    $title = pht('Edit Subversion Info (%s)', $repository->getName());

    $policies = id(new PhabricatorPolicyQuery())
      ->setViewer($viewer)
      ->setObject($repository)
      ->execute();

    $form = id(new AphrontFormView())
      ->setUser($viewer)
      ->appendRemarkupInstructions(
        pht(
          "You can set the **Repository UUID**, which will help Phabriactor ".
          "provide better context in some cases. You can find the UUID of a ".
          "repository by running `svn info`.".
          "\n\n".
          "If you want to import only part of a repository, like `trunk/`, ".
          "you can set a path in **Import Only**. Phabricator will ignore ".
          "commits which do not affect this path."))
      ->appendChild(
        id(new AphrontFormTextControl())
          ->setName('uuid')
          ->setLabel(pht('Repository UUID'))
          ->setValue($v_uuid))
      ->appendChild(
        id(new AphrontFormTextControl())
          ->setName('subpath')
          ->setLabel(pht('Import Only'))
          ->setValue($v_subpath))
      ->appendChild(
        id(new AphrontFormSubmitControl())
          ->setValue(pht('Save Subversion Info'))
          ->addCancelButton($edit_uri));

    $form_box = id(new PHUIObjectBoxView())
      ->setHeaderText($title)
      ->setForm($form);

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $form_box,
      ),
      array(
        'title' => $title,
        'device' => true,
      ));
  }

}
