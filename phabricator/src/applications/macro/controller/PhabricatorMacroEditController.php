<?php

final class PhabricatorMacroEditController
  extends PhabricatorMacroController {

  private $id;

  public function willProcessRequest(array $data) {
    $this->id = idx($data, 'id');
  }

  public function processRequest() {

    $this->requireApplicationCapability(
      PhabricatorMacroCapabilityManage::CAPABILITY);

    $request = $this->getRequest();
    $user = $request->getUser();

    if ($this->id) {
      $macro = id(new PhabricatorMacroQuery())
        ->setViewer($user)
        ->withIDs(array($this->id))
        ->executeOne();
      if (!$macro) {
        return new Aphront404Response();
      }
    } else {
      $macro = new PhabricatorFileImageMacro();
      $macro->setAuthorPHID($user->getPHID());
    }

    $errors = array();
    $e_name = true;
    $e_file = null;
    $file = null;
    $can_fetch = PhabricatorEnv::getEnvConfig('security.allow-outbound-http');

    if ($request->isFormPost()) {
      $original = clone $macro;

      $new_name = null;
      if ($request->getBool('name_form') || !$macro->getID()) {
        $new_name = $request->getStr('name');

        $macro->setName($new_name);

        if (!strlen($macro->getName())) {
          $errors[] = pht('Macro name is required.');
          $e_name = pht('Required');
        } else if (!preg_match('/^[a-z0-9:_-]{3,}$/', $macro->getName())) {
          $errors[] = pht(
            'Macro must be at least three characters long and contain only '.
            'lowercase letters, digits, hyphens, colons and underscores.');
          $e_name = pht('Invalid');
        } else {
          $e_name = null;
        }
      }

      $file = null;
      if ($request->getFileExists('file')) {
        $file = PhabricatorFile::newFromPHPUpload(
          $_FILES['file'],
          array(
            'name' => $request->getStr('name'),
            'authorPHID' => $user->getPHID(),
            'isExplicitUpload' => true,
          ));
      } else if ($request->getStr('url')) {
        try {
          $file = PhabricatorFile::newFromFileDownload(
            $request->getStr('url'),
            array(
              'name' => $request->getStr('name'),
              'authorPHID' => $user->getPHID(),
              'isExplicitUpload' => true,
            ));
        } catch (Exception $ex) {
          $errors[] = pht('Could not fetch URL: %s', $ex->getMessage());
        }
      } else if ($request->getStr('phid')) {
        $file = id(new PhabricatorFileQuery())
          ->setViewer($user)
          ->withPHIDs(array($request->getStr('phid')))
          ->executeOne();
      }

      if ($file) {
        if (!$file->isViewableInBrowser()) {
          $errors[] = pht('You must upload an image.');
          $e_file = pht('Invalid');
        } else {
          $macro->setFilePHID($file->getPHID());
          $macro->attachFile($file);
          $e_file = null;
        }
      }

      if (!$macro->getID() && !$file) {
        $errors[] = pht('You must upload an image to create a macro.');
        $e_file = pht('Required');
      }

      if (!$errors) {
        try {
          $xactions = array();

          if ($new_name !== null) {
            $xactions[] = id(new PhabricatorMacroTransaction())
              ->setTransactionType(PhabricatorMacroTransactionType::TYPE_NAME)
              ->setNewValue($new_name);
          }

          if ($file) {
            $xactions[] = id(new PhabricatorMacroTransaction())
              ->setTransactionType(PhabricatorMacroTransactionType::TYPE_FILE)
              ->setNewValue($file->getPHID());
          }

          $editor = id(new PhabricatorMacroEditor())
            ->setActor($user)
            ->setContinueOnNoEffect(true)
            ->setContentSourceFromRequest($request);

          $xactions = $editor->applyTransactions($original, $xactions);

          $view_uri = $this->getApplicationURI('/view/'.$original->getID().'/');
          return id(new AphrontRedirectResponse())->setURI($view_uri);
        } catch (AphrontQueryDuplicateKeyException $ex) {
          throw $ex;
          $errors[] = pht('Macro name is not unique!');
          $e_name = pht('Duplicate');
        }
      }
    }

    if ($errors) {
      $error_view = new AphrontErrorView();
      $error_view->setTitle(pht('Form Errors'));
      $error_view->setErrors($errors);
    } else {
      $error_view = null;
    }

    $current_file = null;
    if ($macro->getFilePHID()) {
      $current_file = $macro->getFile();
    }

    $form = new AphrontFormView();
    $form->addHiddenInput('name_form', 1);
    $form->setUser($request->getUser());

    $form
      ->setEncType('multipart/form-data')
      ->appendChild(
        id(new AphrontFormTextControl())
          ->setLabel(pht('Name'))
          ->setName('name')
          ->setValue($macro->getName())
          ->setCaption(
            pht('This word or phrase will be replaced with the image.'))
          ->setError($e_name));

    if (!$macro->getID()) {
      if ($current_file) {
        $current_file_view = id(new PhabricatorFileLinkView())
          ->setFilePHID($current_file->getPHID())
          ->setFileName($current_file->getName())
          ->setFileViewable(true)
          ->setFileViewURI($current_file->getBestURI())
          ->render();
        $form->addHiddenInput('phid', $current_file->getPHID());
        $form->appendChild(
          id(new AphrontFormMarkupControl())
            ->setLabel(pht('Selected File'))
            ->setValue($current_file_view));

        $other_label = pht('Change File');
      } else {
        $other_label = pht('File');
      }

      if ($can_fetch) {
        $form->appendChild(
          id(new AphrontFormTextControl())
            ->setLabel(pht('URL'))
            ->setName('url')
            ->setValue($request->getStr('url'))
            ->setError($request->getFileExists('file') ? false : $e_file));
      }

      $form->appendChild(
        id(new AphrontFormFileControl())
          ->setLabel($other_label)
          ->setName('file')
          ->setError($request->getStr('url') ? false : $e_file));
    }


    $view_uri = $this->getApplicationURI('/view/'.$macro->getID().'/');

    if ($macro->getID()) {
      $cancel_uri = $view_uri;
    } else {
      $cancel_uri = $this->getApplicationURI();
    }

    $form
      ->appendChild(
        id(new AphrontFormSubmitControl())
          ->setValue(pht('Save Image Macro'))
          ->addCancelButton($cancel_uri));

    $crumbs = $this->buildApplicationCrumbs();

    if ($macro->getID()) {
      $title = pht('Edit Image Macro');
      $crumb = pht('Edit Macro');

      $crumbs->addCrumb(
        id(new PhabricatorCrumbView())
          ->setHref($view_uri)
          ->setName(pht('Macro "%s"', $macro->getName())));
    } else {
      $title = pht('Create Image Macro');
      $crumb = pht('Create Macro');
    }

    $crumbs->addCrumb(
      id(new PhabricatorCrumbView())
        ->setHref($request->getRequestURI())
        ->setName($crumb));

    $upload = null;
    if ($macro->getID()) {
      $upload_form = id(new AphrontFormView())
        ->setEncType('multipart/form-data')
        ->setUser($request->getUser());

      if ($can_fetch) {
        $upload_form->appendChild(
          id(new AphrontFormTextControl())
            ->setLabel(pht('URL'))
            ->setName('url')
            ->setValue($request->getStr('url')));
      }

      $upload_form
        ->appendChild(
          id(new AphrontFormFileControl())
            ->setLabel(pht('File'))
            ->setName('file'))
        ->appendChild(
          id(new AphrontFormSubmitControl())
            ->setValue(pht('Upload File')));

      $upload = id(new PHUIObjectBoxView())
        ->setHeaderText(pht('Upload New File'))
        ->setForm($upload_form);
    }

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
