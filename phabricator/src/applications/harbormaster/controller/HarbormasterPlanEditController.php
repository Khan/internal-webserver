<?php

final class HarbormasterPlanEditController
  extends HarbormasterPlanController {

  private $id;

  public function willProcessRequest(array $data) {
    $this->id = idx($data, 'id');
  }

  public function processRequest() {
    $request = $this->getRequest();
    $viewer = $request->getUser();

    $this->requireApplicationCapability(
      HarbormasterCapabilityManagePlans::CAPABILITY);

    if ($this->id) {
      $plan = id(new HarbormasterBuildPlanQuery())
        ->setViewer($viewer)
        ->withIDs(array($this->id))
        ->executeOne();
      if (!$plan) {
        return new Aphront404Response();
      }
    } else {
      $plan = HarbormasterBuildPlan::initializeNewBuildPlan($viewer);
    }

    $e_name = true;
    $v_name = $plan->getName();
    $validation_exception = null;
    if ($request->isFormPost()) {
      $xactions = array();

      $v_name = $request->getStr('name');
      $e_name = null;
      $type_name = HarbormasterBuildPlanTransaction::TYPE_NAME;

      $xactions[] = id(new HarbormasterBuildPlanTransaction())
        ->setTransactionType($type_name)
        ->setNewValue($v_name);

      $editor = id(new HarbormasterBuildPlanEditor())
        ->setActor($viewer)
        ->setContentSourceFromRequest($request);

      try {
        $editor->applyTransactions($plan, $xactions);
        return id(new AphrontRedirectResponse())
          ->setURI($this->getApplicationURI('plan/'.$plan->getID().'/'));
      } catch (PhabricatorApplicationTransactionValidationException $ex) {
        $validation_exception = $ex;

        $e_name = $validation_exception->getShortMessage(
          HarbormasterBuildPlanTransaction::TYPE_NAME);
      }

    }

    $is_new = (!$plan->getID());
    if ($is_new) {
      $title = pht('New Build Plan');
      $cancel_uri = $this->getApplicationURI();
      $save_button = pht('Create Build Plan');
    } else {
      $id = $plan->getID();

      $title = pht('Edit Build Plan');
      $cancel_uri = "/B{$id}";
      $save_button = pht('Save Build Plan');
    }

    $form = id(new AphrontFormView())
      ->setUser($viewer)
      ->appendChild(
        id(new AphrontFormTextControl())
          ->setLabel('Plan Name')
          ->setName('name')
          ->setError($e_name)
          ->setValue($v_name));

    $form->appendChild(
      id(new AphrontFormSubmitControl())
        ->setValue($save_button)
        ->addCancelButton($cancel_uri));

    $box = id(new PHUIObjectBoxView())
      ->setHeaderText($title)
      ->setValidationException($validation_exception)
      ->setForm($form);

    $crumbs = $this->buildApplicationCrumbs();
    if ($is_new) {
      $crumbs->addCrumb(
        id(new PhabricatorCrumbView())
          ->setName(pht('New Build Plan')));
    } else {
      $id = $plan->getID();
      $crumbs->addCrumb(
        id(new PhabricatorCrumbView())
          ->setName(pht("Plan %d", $id))
          ->setHref($this->getApplicationURI("plan/{$id}/")));
      $crumbs->addCrumb(
        id(new PhabricatorCrumbView())
          ->setName(pht('Edit')));
    }

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $box,
      ),
      array(
        'title' => $title,
        'device' => true,
      ));
  }

}
