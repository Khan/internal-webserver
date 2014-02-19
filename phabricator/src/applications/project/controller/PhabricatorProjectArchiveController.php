<?php

final class PhabricatorProjectArchiveController
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

    $edit_uri = $this->getApplicationURI('edit/'.$project->getID().'/');

    if ($request->isFormPost()) {
      if ($project->isArchived()) {
        $new_status = PhabricatorProjectStatus::STATUS_ACTIVE;
      } else {
        $new_status = PhabricatorProjectStatus::STATUS_ARCHIVED;
      }

      $xactions = array();

      $xactions[] = id(new PhabricatorProjectTransaction())
        ->setTransactionType(PhabricatorProjectTransaction::TYPE_STATUS)
        ->setNewValue($new_status);

      id(new PhabricatorProjectTransactionEditor())
        ->setActor($viewer)
        ->setContentSourceFromRequest($request)
        ->setContinueOnNoEffect(true)
        ->setContinueOnMissingFields(true)
        ->applyTransactions($project, $xactions);

      return id(new AphrontRedirectResponse())->setURI($edit_uri);
    }

    if ($project->isArchived()) {
      $title = pht('Really unarchive project?');
      $body = pht('This project will become active again.');
      $button = pht('Unarchive Project');
    } else {
      $title = pht('Really archive project?');
      $body = pht('This project will moved to the archive.');
      $button = pht('Archive Project');
    }

    $dialog = id(new AphrontDialogView())
      ->setUser($viewer)
      ->setTitle($title)
      ->appendChild($body)
      ->addCancelButton($edit_uri)
      ->addSubmitButton($button);

    return id(new AphrontDialogResponse())->setDialog($dialog);
  }

}
