<?php

final class PhabricatorRepositoryArcanistProjectDeleteController
  extends PhabricatorRepositoryController {

  private $id;

  public function willProcessRequest(array $data) {
    $this->id = $data['id'];
  }

  public function processRequest() {

    $arc_project =
      id(new PhabricatorRepositoryArcanistProject())->load($this->id);
    if (!$arc_project) {
      return new Aphront404Response();
    }

    $request = $this->getRequest();

    if ($request->isDialogFormPost()) {
      $arc_project->delete();
      return id(new AphrontRedirectResponse())->setURI('/repository/');
    }

    $dialog = new AphrontDialogView();
    $dialog
      ->setUser($request->getUser())
      ->setTitle('Really delete this arcanist project?')
      ->appendChild(hsprintf(
        '<p>Really delete the "%s" arcanist project? '.
          'This operation can not be undone.</p>',
        $arc_project->getName()))
      ->setSubmitURI('/repository/project/delete/'.$this->id.'/')
      ->addSubmitButton('Delete Arcanist Project')
      ->addCancelButton('/repository/');

    return id(new AphrontDialogResponse())->setDialog($dialog);
  }
}
