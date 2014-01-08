<?php

final class HarbormasterPlanRunController
  extends HarbormasterController {

  private $id;

  public function willProcessRequest(array $data) {
    $this->id = $data['id'];
  }

  public function processRequest() {
    $request = $this->getRequest();
    $viewer = $request->getUser();

    $this->requireApplicationCapability(
      HarbormasterCapabilityManagePlans::CAPABILITY);

    $plan_id = $this->id;
    $plan = id(new HarbormasterBuildPlanQuery())
      ->setViewer($viewer)
      ->withIDs(array($plan_id))
      ->executeOne();
    if (!$plan) {
      return new Aphront404Response();
    }

    $e_name = true;
    $v_name = null;

    $errors = array();
    if ($request->isFormPost()) {
      $buildable = HarbormasterBuildable::initializeNewBuildable($viewer)
        ->setIsManualBuildable(true);

      $v_name = $request->getStr('buildablePHID');

      if ($v_name) {
        $object = id(new PhabricatorObjectQuery())
          ->setViewer($viewer)
          ->withNames(array($v_name))
          ->executeOne();

        if ($object instanceof HarbormasterBuildableInterface) {
          $buildable
            ->setBuildablePHID($object->getHarbormasterBuildablePHID())
            ->setContainerPHID($object->getHarbormasterContainerPHID());
        } else {
          $e_name = pht('Invalid');
          $errors[] = pht('Enter the name of a revision or commit.');
        }
      } else {
        $e_name = pht('Required');
        $errors[] = pht('You must choose a revision or commit to build.');
      }

      if (!$errors) {
        $buildable->save();
        $buildable->applyPlan($plan);

        $buildable_uri = '/B'.$buildable->getID();
        return id(new AphrontRedirectResponse())->setURI($buildable_uri);
      }
    }

    if ($errors) {
      $errors = id(new AphrontErrorView())->setErrors($errors);
    }

    $title = pht('Run Build Plan Manually');
    $cancel_uri = $this->getApplicationURI("plan/{$plan_id}/");
    $save_button = pht('Run Plan Manually');

    $form = id(new PHUIFormLayoutView())
      ->setUser($viewer)
      ->appendRemarkupInstructions(
        pht(
          "Enter the name of a commit or revision to run this plan on (for ".
          "example, `rX123456` or `D123`).\n\n".
          "For more detailed output, you can also run manual builds from ".
          "the command line:\n\n".
          "  phabricator/ $ ./bin/harbormaster build <object> --plan %s",
          $plan->getID()))
      ->appendChild(
        id(new AphrontFormTextControl())
          ->setLabel('Buildable Name')
          ->setName('buildablePHID')
          ->setError($e_name)
          ->setValue($v_name));

    $dialog = id(new AphrontDialogView())
      ->setWidth(AphrontDialogView::WIDTH_FULL)
      ->setUser($viewer)
      ->setTitle($title)
      ->appendChild($form)
      ->addCancelButton($cancel_uri)
      ->addSubmitButton($save_button);

    return id(new AphrontDialogResponse())->setDialog($dialog);
  }

}
