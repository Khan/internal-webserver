<?php

final class PhabricatorOwnersDeleteController
  extends PhabricatorOwnersController {

  private $id;

  public function willProcessRequest(array $data) {
    $this->id = $data['id'];
  }

  public function processRequest() {
    $request = $this->getRequest();
    $user = $request->getUser();

    $package = id(new PhabricatorOwnersPackage())->load($this->id);
    if (!$package) {
      return new Aphront404Response();
    }

    if ($request->isDialogFormPost()) {
      $package->attachActorPHID($user->getPHID());
      $package->delete();
      return id(new AphrontRedirectResponse())->setURI('/owners/');
    }

    $text = pht('Are you sure you want to delete the "%s" package? This '.
          'operation can not be undone.', $package->getName());
    $dialog = id(new AphrontDialogView())
      ->setUser($user)
      ->setTitle('Really delete this package?')
      ->setHeaderColor(PhabricatorActionHeaderView::HEADER_RED)
      ->appendChild(hsprintf(
        '<p>%s</p>',
        $text))
      ->addSubmitButton(pht('Delete'))
      ->addCancelButton('/owners/package/'.$package->getID().'/')
      ->setSubmitURI($request->getRequestURI());

    return id(new AphrontDialogResponse())->setDialog($dialog);
  }

}
