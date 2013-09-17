<?php

final class DifferentialRevisionEditController extends DifferentialController {

  private $id;

  public function willProcessRequest(array $data) {
    $this->id = idx($data, 'id');
  }

  public function processRequest() {
    $request = $this->getRequest();
    $viewer = $request->getUser();

    if (!$this->id) {
      $this->id = $request->getInt('revisionID');
    }

    if ($this->id) {
      $revision = id(new DifferentialRevisionQuery())
        ->setViewer($viewer)
        ->withIDs(array($this->id))
        ->needRelationships(true)
        ->needReviewerStatus(true)
        ->executeOne();
      if (!$revision) {
        return new Aphront404Response();
      }
    } else {
      $revision = new DifferentialRevision();
      $revision->attachRelationships(array());
    }

    $aux_fields = $this->loadAuxiliaryFields($revision);

    $diff_id = $request->getInt('diffID');
    if ($diff_id) {
      $diff = id(new DifferentialDiffQuery())
        ->setViewer($viewer)
        ->withIDs(array($diff_id))
        ->executeOne();
      if (!$diff) {
        return new Aphront404Response();
      }
      if ($diff->getRevisionID()) {
        // TODO: Redirect?
        throw new Exception("This diff is already attached to a revision!");
      }
    } else {
      $diff = null;
    }

    $errors = array();


    if ($request->isFormPost() && !$request->getStr('viaDiffView')) {
      foreach ($aux_fields as $aux_field) {
        $aux_field->setValueFromRequest($request);
        try {
          $aux_field->validateField();
        } catch (DifferentialFieldValidationException $ex) {
          $errors[] = $ex->getMessage();
        }
      }

      if (!$errors) {
        $is_new = !$revision->getID();
        $user = $request->getUser();

        $editor = new DifferentialRevisionEditor($revision);
        $editor->setActor($request->getUser());
        if ($diff) {
          $editor->addDiff($diff, $request->getStr('comments'));
        }
        $editor->setAuxiliaryFields($aux_fields);
        $editor->setAphrontRequestForEventDispatch($request);
        $editor->save();

        return id(new AphrontRedirectResponse())
          ->setURI('/D'.$revision->getID());
      }
    }

    $aux_phids = array();
    foreach ($aux_fields as $key => $aux_field) {
      $aux_phids[$key] = $aux_field->getRequiredHandlePHIDsForRevisionEdit();
    }
    $phids = array_mergev($aux_phids);
    $phids = array_unique($phids);
    $handles = $this->loadViewerHandles($phids);
    foreach ($aux_fields as $key => $aux_field) {
      $aux_field->setHandles(array_select_keys($handles, $aux_phids[$key]));
    }

    $form = new AphrontFormView();
    $form->setUser($request->getUser());
    if ($diff) {
      $form->addHiddenInput('diffID', $diff->getID());
    }

    if ($revision->getID()) {
      $form->setAction('/differential/revision/edit/'.$revision->getID().'/');
    } else {
      $form->setAction('/differential/revision/edit/');
    }

    $error_view = null;
    if ($errors) {
      $error_view = id(new AphrontErrorView())
        ->setTitle(pht('Form Errors'))
        ->setErrors($errors);
    }

    if ($diff && $revision->getID()) {
      $form
        ->appendChild(
          id(new AphrontFormTextAreaControl())
            ->setLabel(pht('Comments'))
            ->setName('comments')
            ->setCaption(pht("Explain what's new in this diff."))
            ->setValue($request->getStr('comments')))
        ->appendChild(
          id(new AphrontFormSubmitControl())
            ->setValue(pht('Save')))
        ->appendChild(
          id(new AphrontFormDividerControl()));
    }

    $preview = array();
    foreach ($aux_fields as $aux_field) {
      $control = $aux_field->renderEditControl();
      if ($control) {
        $form->appendChild($control);
      }
      $preview[] = $aux_field->renderEditPreview();
    }

    $submit = id(new AphrontFormSubmitControl())
      ->setValue('Save');
    if ($diff) {
      $submit->addCancelButton('/differential/diff/'.$diff->getID().'/');
    } else {
      $submit->addCancelButton('/D'.$revision->getID());
    }

    $form->appendChild($submit);

    $crumbs = $this->buildApplicationCrumbs();
    if ($revision->getID()) {
      if ($diff) {
        $title = pht('Update Differential Revision');
        $crumbs->addCrumb(
          id(new PhabricatorCrumbView())
            ->setName('D'.$revision->getID())
            ->setHref('/differential/diff/'.$diff->getID().'/'));
      } else {
        $title = pht('Edit Differential Revision');
        $crumbs->addCrumb(
          id(new PhabricatorCrumbView())
            ->setName('D'.$revision->getID())
            ->setHref('/D'.$revision->getID()));
      }
    } else {
      $title = pht('Create New Differential Revision');
    }

    $form_box = id(new PHUIFormBoxView())
      ->setHeaderText($title)
      ->setFormError($error_view)
      ->setForm($form);

    $crumbs->addCrumb(
      id(new PhabricatorCrumbView())
        ->setName($title));

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $form_box,
        $preview),
      array(
        'title' => $title,
        'device' => true,
      ));
  }

  private function loadAuxiliaryFields(DifferentialRevision $revision) {

    $user = $this->getRequest()->getUser();

    $aux_fields = DifferentialFieldSelector::newSelector()
      ->getFieldSpecifications();
    foreach ($aux_fields as $key => $aux_field) {
      $aux_field->setRevision($revision);
      if (!$aux_field->shouldAppearOnEdit()) {
        unset($aux_fields[$key]);
      } else {
        $aux_field->setUser($user);
      }
    }

    return DifferentialAuxiliaryField::loadFromStorage(
      $revision,
      $aux_fields);
  }

}
