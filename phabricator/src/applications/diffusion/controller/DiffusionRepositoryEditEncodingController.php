<?php

final class DiffusionRepositoryEditEncodingController
  extends DiffusionController {

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

    $v_encoding = $repository->getDetail('encoding');
    $e_encoding = null;
    $errors = array();

    if ($request->isFormPost()) {
      $v_encoding = $request->getStr('encoding');

      if (!$errors) {
        $xactions = array();
        $template = id(new PhabricatorRepositoryTransaction());

        $type_encoding = PhabricatorRepositoryTransaction::TYPE_ENCODING;

        $xactions[] = id(clone $template)
          ->setTransactionType($type_encoding)
          ->setNewValue($v_encoding);

        try {
          id(new PhabricatorRepositoryEditor())
            ->setContinueOnNoEffect(true)
            ->setContentSourceFromRequest($request)
            ->setActor($user)
            ->applyTransactions($repository, $xactions);

          return id(new AphrontRedirectResponse())->setURI($edit_uri);
        } catch (Exception $ex) {
          $errors[] = $ex->getMessage();
        }
      }
    }

    $content = array();

    $crumbs = $this->buildCrumbs();
    $crumbs->addCrumb(
      id(new PhabricatorCrumbView())
        ->setName(pht('Edit Encoding')));
    $content[] = $crumbs;

    $title = pht('Edit %s', $repository->getName());

    if ($errors) {
      $content[] = id(new AphrontErrorView())
        ->setTitle(pht('Form Errors'))
        ->setErrors($errors);
    }

    $form = id(new AphrontFormView())
      ->setUser($user)
      ->setFlexible(true)
      ->appendRemarkupInstructions($this->getEncodingInstructions())
      ->appendChild(
        id(new AphrontFormTextControl())
          ->setName('encoding')
          ->setLabel(pht('Text Encoding'))
          ->setValue($v_encoding)
          ->setError($e_encoding))
      ->appendChild(
        id(new AphrontFormSubmitControl())
          ->setValue(pht('Save Encoding'))
          ->addCancelButton($edit_uri));

    $content[] = $form;

    return $this->buildApplicationPage(
      $content,
      array(
        'title' => $title,
        'dust' => true,
        'device' => true,
      ));
  }

  private function getEncodingInstructions() {
    return pht(<<<EOT
If source code in this repository uses a character encoding other than UTF-8
(for example, `ISO-8859-1`), specify it here.

**Normally, you can leave this field blank.** If your source code is written in
ASCII or UTF-8, everything will work correctly.

Source files will be translated from the specified encoding to UTF-8 when they
are read from the repository, before they are displayed in Diffusion.

See [[%s | UTF-8 and Character Encoding]] for more information on how
Phabricator handles text encodings.
EOT
    ,
    PhabricatorEnv::getDoclink(
      'article/User_Guide_UTF-8_and_Character_Encoding.html'));
  }

}
