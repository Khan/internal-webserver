<?php

final class PhabricatorProjectProfileEditController
  extends PhabricatorProjectController {

  private $id;

  public function willProcessRequest(array $data) {
    $this->id = $data['id'];
  }

  public function processRequest() {
    $request = $this->getRequest();
    $viewer = $request->getUser();

    $project = id(new PhabricatorProjectQuery())
      ->setViewer($viewer)
      ->withIDs(array($this->id))
      ->requireCapabilities(
        array(
          PhabricatorPolicyCapability::CAN_VIEW,
          PhabricatorPolicyCapability::CAN_EDIT,
        ))
      ->executeOne();
    if (!$project) {
      return new Aphront404Response();
    }

    $field_list = PhabricatorCustomField::getObjectFields(
      $project,
      PhabricatorCustomField::ROLE_EDIT);
    $field_list
      ->setViewer($viewer)
      ->readFieldsFromStorage($project);

    $view_uri = $this->getApplicationURI('view/'.$project->getID().'/');

    $e_name = true;
    $e_edit = null;

    $v_name = $project->getName();

    $validation_exception = null;

    if ($request->isFormPost()) {
      $e_name = null;

      $v_name = $request->getStr('name');
      $v_view = $request->getStr('can_view');
      $v_edit = $request->getStr('can_edit');
      $v_join = $request->getStr('can_join');

      $xactions = $field_list->buildFieldTransactionsFromRequest(
        new PhabricatorProjectTransaction(),
        $request);

      $type_name = PhabricatorProjectTransaction::TYPE_NAME;
      $type_edit = PhabricatorTransactions::TYPE_EDIT_POLICY;

      $xactions[] = id(new PhabricatorProjectTransaction())
        ->setTransactionType($type_name)
        ->setNewValue($request->getStr('name'));

      $xactions[] = id(new PhabricatorProjectTransaction())
        ->setTransactionType(PhabricatorTransactions::TYPE_VIEW_POLICY)
        ->setNewValue($v_view);

      $xactions[] = id(new PhabricatorProjectTransaction())
        ->setTransactionType($type_edit)
        ->setNewValue($v_edit);

      $xactions[] = id(new PhabricatorProjectTransaction())
        ->setTransactionType(PhabricatorTransactions::TYPE_JOIN_POLICY)
        ->setNewValue($v_join);

      $editor = id(new PhabricatorProjectTransactionEditor())
        ->setActor($viewer)
        ->setContentSourceFromRequest($request)
        ->setContinueOnNoEffect(true);

      try {
        $editor->applyTransactions($project, $xactions);

        return id(new AphrontRedirectResponse())->setURI($view_uri);
      } catch (PhabricatorApplicationTransactionValidationException $ex) {
        $validation_exception = $ex;

        $e_name = $ex->getShortMessage($type_name);
        $e_edit = $ex->getShortMessage($type_edit);

        $project->setViewPolicy($v_view);
        $project->setEditPolicy($v_edit);
        $project->setJoinPolicy($v_join);
      }
    }

    $header_name = pht('Edit Project');
    $title = pht('Edit Project');

    $policies = id(new PhabricatorPolicyQuery())
      ->setViewer($viewer)
      ->setObject($project)
      ->execute();

    $form = new AphrontFormView();
    $form
      ->setUser($viewer)
      ->appendChild(
        id(new AphrontFormTextControl())
          ->setLabel(pht('Name'))
          ->setName('name')
          ->setValue($v_name)
          ->setError($e_name));

    $field_list->appendFieldsToForm($form);

    $form
      ->appendChild(
        id(new AphrontFormPolicyControl())
          ->setUser($viewer)
          ->setName('can_view')
          ->setCaption(pht('Members can always view a project.'))
          ->setPolicyObject($project)
          ->setPolicies($policies)
          ->setCapability(PhabricatorPolicyCapability::CAN_VIEW))
      ->appendChild(
        id(new AphrontFormPolicyControl())
          ->setUser($viewer)
          ->setName('can_edit')
          ->setPolicyObject($project)
          ->setPolicies($policies)
          ->setCapability(PhabricatorPolicyCapability::CAN_EDIT)
          ->setError($e_edit))
      ->appendChild(
        id(new AphrontFormPolicyControl())
          ->setUser($viewer)
          ->setName('can_join')
          ->setCaption(
            pht('Users who can edit a project can always join a project.'))
          ->setPolicyObject($project)
          ->setPolicies($policies)
          ->setCapability(PhabricatorPolicyCapability::CAN_JOIN))
      ->appendChild(
        id(new AphrontFormSubmitControl())
          ->addCancelButton($view_uri)
          ->setValue(pht('Save')));

    $form_box = id(new PHUIObjectBoxView())
      ->setHeaderText($title)
      ->setValidationException($validation_exception)
      ->setForm($form);

    $crumbs = $this->buildApplicationCrumbs($this->buildSideNavView())
      ->addTextCrumb($project->getName(), $view_uri)
      ->addTextCrumb(pht('Edit Project'), $this->getApplicationURI());

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
