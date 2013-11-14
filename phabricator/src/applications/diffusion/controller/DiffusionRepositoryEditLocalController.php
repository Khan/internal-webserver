<?php

final class DiffusionRepositoryEditLocalController
  extends DiffusionRepositoryEditController {

  public function processRequest() {
    $request = $this->getRequest();
    $user = $request->getUser();
    $drequest = $this->diffusionRequest;
    $repository = $drequest->getRepository();

    $repository = id(new PhabricatorRepositoryQuery())
      ->setViewer($user)
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

    $edit_uri = $this->getRepositoryControllerURI($repository, 'edit/');

    $v_local = $repository->getHumanReadableDetail('local-path');
    $errors = array();

    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addCrumb(
      id(new PhabricatorCrumbView())
        ->setName(pht('Edit Local')));

    $title = pht('Edit %s', $repository->getName());

    $error_view = null;
    if ($errors) {
      $error_view = id(new AphrontErrorView())
        ->setTitle(pht('Form Errors'))
        ->setErrors($errors);
    }

    $form = id(new AphrontFormView())
      ->setUser($user)
      ->appendRemarkupInstructions(
        pht(
          "You can not adjust the local path for this repository from the ".
          "web interface. To edit it, run this command:\n\n".
          "  phabricator/ $ ./bin/repository edit %s --as %s --local-path ...",
          $repository->getCallsign(),
          $user->getUsername()))
      ->appendChild(
        id(new AphrontFormMarkupControl())
          ->setName('local')
          ->setLabel(pht('Local Path'))
          ->setValue($v_local))
      ->appendChild(
        id(new AphrontFormSubmitControl())
          ->addCancelButton($edit_uri, pht('Done')));

    $object_box = id(new PHUIObjectBoxView())
      ->setHeaderText($title)
      ->setForm($form)
      ->setFormError($error_view);

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $object_box,
      ),
      array(
        'title' => $title,
        'device' => true,
      ));
  }

}
