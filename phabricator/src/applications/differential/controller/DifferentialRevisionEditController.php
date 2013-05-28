<?php

final class DifferentialRevisionEditController extends DifferentialController {

  private $id;

  public function willProcessRequest(array $data) {
    $this->id = idx($data, 'id');
  }

  public function processRequest() {

    $request = $this->getRequest();

    if (!$this->id) {
      $this->id = $request->getInt('revisionID');
    }

    if ($this->id) {
      $revision = id(new DifferentialRevision())->load($this->id);
      if (!$revision) {
        return new Aphront404Response();
      }
    } else {
      $revision = new DifferentialRevision();
    }

    $revision->loadRelationships();
    $aux_fields = $this->loadAuxiliaryFields($revision);

    $diff_id = $request->getInt('diffID');
    if ($diff_id) {
      $diff = id(new DifferentialDiff())->load($diff_id);
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

        $event = new PhabricatorEvent(
          PhabricatorEventType::TYPE_DIFFERENTIAL_WILLEDITREVISION,
            array(
              'revision'      => $revision,
              'new'           => $is_new,
            ));

        $event->setUser($user);
        $event->setAphrontRequest($request);
        PhutilEventEngine::dispatchEvent($event);

        $editor = new DifferentialRevisionEditor($revision);
        $editor->setActor($request->getUser());
        if ($diff) {
          $editor->addDiff($diff, $request->getStr('comments'));
        }
        $editor->setAuxiliaryFields($aux_fields);
        $editor->save();

        $event = new PhabricatorEvent(
          PhabricatorEventType::TYPE_DIFFERENTIAL_DIDEDITREVISION,
            array(
              'revision'      => $revision,
              'new'           => $is_new,
            ));
        $event->setUser($user);
        $event->setAphrontRequest($request);
        PhutilEventEngine::dispatchEvent($event);

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
    $form->setFlexible(true);
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

    foreach ($aux_fields as $aux_field) {
      $control = $aux_field->renderEditControl();
      if ($control) {
        $form->appendChild($control);
      }
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

    $crumbs->addCrumb(
      id(new PhabricatorCrumbView())
        ->setName($title)
        ->setHref(''));

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $error_view,
        $form),
      array(
        'title' => $title,
        'device' => true,
        'dust' => true,
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
