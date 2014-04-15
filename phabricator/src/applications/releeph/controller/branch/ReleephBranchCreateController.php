<?php

final class ReleephBranchCreateController extends ReleephProductController {

  private $productID;

  public function willProcessRequest(array $data) {
    $this->productID = $data['projectID'];
  }

  public function processRequest() {
    $request = $this->getRequest();
    $viewer = $request->getUser();

    $product = id(new ReleephProjectQuery())
      ->setViewer($viewer)
      ->withIDs(array($this->productID))
      ->requireCapabilities(
        array(
          PhabricatorPolicyCapability::CAN_VIEW,
          PhabricatorPolicyCapability::CAN_EDIT,
        ))
      ->executeOne();
    if (!$product) {
      return new Aphront404Response();
    }
    $this->setProduct($product);


    $cut_point = $request->getStr('cutPoint');
    $symbolic_name = $request->getStr('symbolicName');

    if (!$cut_point) {
      $repository = $product->getRepository();
      switch ($repository->getVersionControlSystem()) {
        case PhabricatorRepositoryType::REPOSITORY_TYPE_SVN:
          break;
        case PhabricatorRepositoryType::REPOSITORY_TYPE_GIT:
          $cut_point = $product->getTrunkBranch();
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
            ->setReleephProject($product);
          $cut_commit = $finder->fromPartial($cut_point);
        } catch (Exception $e) {
          $e_cut = pht('Invalid');
          $errors[] = $e->getMessage();
        }
      }

      if (!$errors) {
        $branch = id(new ReleephBranchEditor())
          ->setReleephProject($product)
          ->setActor($request->getUser())
          ->newBranchFromCommit(
            $cut_commit,
            $branch_date,
            $symbolic_name);

        $branch_uri = $this->getApplicationURI('branch/'.$branch->getID());

        return id(new AphrontRedirectResponse())
          ->setURI($branch_uri);
      }
    }

    $product_uri = $this->getProductViewURI($product);

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
          ->addCancelButton($product_uri));

    $box = id(new PHUIObjectBoxView())
      ->setHeaderText(pht('New Branch'))
      ->setFormErrors($errors)
      ->appendChild($form);

    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addTextCrumb(pht('New Branch'));

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $box,
      ),
      array(
        'title' => pht('New Branch'),
        'device' => true,
      ));
  }
}
