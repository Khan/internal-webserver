<?php

final class PhabricatorMacroMemeDialogController
  extends PhabricatorMacroController {

  public function processRequest() {
    $request = $this->getRequest();
    $user = $request->getUser();

    $phid = head($request->getArr('macro'));
    $above = $request->getStr('above');
    $below = $request->getStr('below');

    $e_macro = true;
    $errors = array();
    if ($request->isDialogFormPost()) {
      if (!$phid) {
        $e_macro = pht('Required');
        $errors[] = pht('Macro name is required.');
      } else {
        $macro = id(new PhabricatorMacroQuery())
          ->setViewer($user)
          ->withPHIDs(array($phid))
          ->executeOne();
        if (!$macro) {
          $e_macro = pht('Invalid');
          $errors[] = pht('No such macro.');
        }
      }

      if (!$errors) {
        $options = new PhutilSimpleOptions();
        $data = array(
          'src' => $macro->getName(),
          'above' => $above,
          'below' => $below,
        );
        $string = $options->unparse($data, $escape = '}');

        $result = array(
          'text' => "{meme, {$string}}",
        );
        return id(new AphrontAjaxResponse())->setContent($result);
      }
    }

    $view = id(new PHUIFormLayoutView())
      ->appendChild(
        id(new AphrontFormTokenizerControl())
          ->setLabel(pht('Macro'))
          ->setName('macro')
          ->setLimit(1)
          ->setDatasource('/typeahead/common/macros/')
          ->setError($e_macro))
      ->appendChild(
        id(new AphrontFormTextControl())
          ->setLabel(pht('Above'))
          ->setName('above')
          ->setValue($above))
      ->appendChild(
        id(new AphrontFormTextControl())
          ->setLabel(pht('Below'))
          ->setName('below')
          ->setValue($below));

    $dialog = id(new AphrontDialogView())
      ->setUser($user)
      ->setTitle(pht('Create Meme'))
      ->appendChild($view)
      ->addCancelButton('/')
      ->addSubmitButton(pht('rofllolo!!~'));

    return id(new AphrontDialogResponse())->setDialog($dialog);
  }

}
