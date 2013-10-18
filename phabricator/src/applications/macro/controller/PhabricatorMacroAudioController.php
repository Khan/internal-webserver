<?php

final class PhabricatorMacroAudioController
  extends PhabricatorMacroController {

  private $id;

  public function willProcessRequest(array $data) {
    $this->id = idx($data, 'id');
  }

  public function processRequest() {

    $this->requireApplicationCapability(
      PhabricatorMacroCapabilityManage::CAPABILITY);

    $request = $this->getRequest();
    $viewer = $request->getUser();

    $macro = id(new PhabricatorMacroQuery())
      ->setViewer($viewer)
      ->requireCapabilities(
        array(
          PhabricatorPolicyCapability::CAN_VIEW,
          PhabricatorPolicyCapability::CAN_EDIT,
        ))
      ->withIDs(array($this->id))
      ->executeOne();

    if (!$macro) {
      return new Aphront404Response();
    }

    $errors = array();
    $view_uri = $this->getApplicationURI('/view/'.$macro->getID().'/');

    $e_file = null;
    $file = null;

    if ($request->isFormPost()) {
      $xactions = array();

      if ($request->getBool('behaviorForm')) {
        $xactions[] = id(new PhabricatorMacroTransaction())
          ->setTransactionType(
            PhabricatorMacroTransactionType::TYPE_AUDIO_BEHAVIOR)
          ->setNewValue($request->getStr('audioBehavior'));
      } else {
        $file = null;
        if ($request->getFileExists('file')) {
          $file = PhabricatorFile::newFromPHPUpload(
            $_FILES['file'],
            array(
              'name' => $request->getStr('name'),
              'authorPHID' => $viewer->getPHID(),
              'isExplicitUpload' => true,
            ));
        }

        if ($file) {
          if (!$file->isAudio()) {
            $errors[] = pht('You must upload audio.');
            $e_file = pht('Invalid');
          } else {
            $xactions[] = id(new PhabricatorMacroTransaction())
              ->setTransactionType(PhabricatorMacroTransactionType::TYPE_AUDIO)
              ->setNewValue($file->getPHID());
          }
        } else {
          $errors[] = pht('You must upload an audio file.');
          $e_file = pht('Required');
        }
      }

      if (!$errors) {
        id(new PhabricatorMacroEditor())
          ->setActor($viewer)
          ->setContinueOnNoEffect(true)
          ->setContentSourceFromRequest($request)
          ->applyTransactions($macro, $xactions);

        return id(new AphrontRedirectResponse())->setURI($view_uri);
      }
    }

    if ($errors) {
      $error_view = new AphrontErrorView();
      $error_view->setTitle(pht('Form Errors'));
      $error_view->setErrors($errors);
    } else {
      $error_view = null;
    }

    $form = id(new AphrontFormView())
      ->addHiddenInput('behaviorForm', 1)
      ->setUser($viewer);

    $options = id(new AphrontFormRadioButtonControl())
      ->setLabel(pht('Audio Behavior'))
      ->setName('audioBehavior')
      ->setValue(
        nonempty(
          $macro->getAudioBehavior(),
          PhabricatorFileImageMacro::AUDIO_BEHAVIOR_NONE));

    $options->addButton(
      PhabricatorFileImageMacro::AUDIO_BEHAVIOR_NONE,
      pht('Do Not Play'),
      pht('Do not play audio.'));

    $options->addButton(
      PhabricatorFileImageMacro::AUDIO_BEHAVIOR_ONCE,
      pht('Play Once'),
      pht('Play audio once, when the viewer looks at the macro.'));

    $options->addButton(
      PhabricatorFileImageMacro::AUDIO_BEHAVIOR_LOOP,
      pht('Play Continuously'),
      pht(
        'Play audio continuously, treating the macro as an audio source. '.
        'Best for ambient sounds.'));

    $form->appendChild($options);

    $form
      ->appendChild(
        id(new AphrontFormSubmitControl())
          ->setValue(pht('Save Audio Behavior'))
          ->addCancelButton($view_uri));

    $crumbs = $this->buildApplicationCrumbs();

    $title = pht('Edit Audio Behavior');
    $crumb = pht('Edit Audio');

    $crumbs->addCrumb(
      id(new PhabricatorCrumbView())
        ->setHref($view_uri)
        ->setName(pht('Macro "%s"', $macro->getName())));

    $crumbs->addCrumb(
      id(new PhabricatorCrumbView())
        ->setHref($request->getRequestURI())
        ->setName($crumb));

    $upload_form = id(new AphrontFormView())
      ->setEncType('multipart/form-data')
      ->setUser($viewer)
      ->appendChild(
        id(new AphrontFormFileControl())
          ->setLabel(pht('Audio File'))
          ->setName('file'))
      ->appendChild(
        id(new AphrontFormSubmitControl())
          ->setValue(pht('Upload File')));

    $upload = id(new PHUIObjectBoxView())
      ->setHeaderText(pht('Upload New Audio'))
      ->setForm($upload_form);

    $form_box = id(new PHUIObjectBoxView())
      ->setHeaderText($title)
      ->setFormError($error_view)
      ->setForm($form);

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $form_box,
        $upload,
      ),
      array(
        'title' => $title,
        'device' => true,
      ));
  }
}
