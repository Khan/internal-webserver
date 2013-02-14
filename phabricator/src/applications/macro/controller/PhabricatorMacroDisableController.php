<?php

final class PhabricatorMacroDisableController
  extends PhabricatorMacroController {

  private $id;

  public function willProcessRequest(array $data) {
    $this->id = $data['id'];
  }

  public function processRequest() {
    $request = $this->getRequest();
    $user = $request->getUser();

    $macro = id(new PhabricatorFileImageMacro())->load($this->id);
    if (!$macro) {
      return new Aphront404Response();
    }

    $view_uri = $this->getApplicationURI('/view/'.$this->id.'/');

    if ($request->isDialogFormPost() || $macro->getIsDisabled()) {
      $xaction = id(new PhabricatorMacroTransaction())
        ->setTransactionType(PhabricatorMacroTransactionType::TYPE_DISABLED)
        ->setNewValue($macro->getIsDisabled() ? 0 : 1);

      $editor = id(new PhabricatorMacroEditor())
        ->setActor($user)
        ->setContentSource(
          PhabricatorContentSource::newForSource(
            PhabricatorContentSource::SOURCE_WEB,
            array(
              'ip' => $request->getRemoteAddr(),
            )));

      $xactions = $editor->applyTransactions($macro, array($xaction));

      return id(new AphrontRedirectResponse())->setURI($view_uri);
    }

    $dialog = new AphrontDialogView();
    $dialog
      ->setUser($request->getUser())
      ->setTitle(pht('Really disable macro?'))
      ->appendChild(phutil_tag('p', array(), pht(
        'Really disable the much-beloved image macro %s? '.
          'It will be sorely missed.',
        $macro->getName())))
      ->setSubmitURI($this->getApplicationURI('/disable/'.$this->id.'/'))
      ->addSubmitButton(pht('Disable'))
      ->addCancelButton($view_uri);

    return id(new AphrontDialogResponse())->setDialog($dialog);
  }
}
