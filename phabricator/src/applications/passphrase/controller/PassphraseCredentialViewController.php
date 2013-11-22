<?php

final class PassphraseCredentialViewController extends PassphraseController {

  private $id;

  public function willProcessRequest(array $data) {
    $this->id = $data['id'];
  }

  public function processRequest() {
    $request = $this->getRequest();
    $viewer = $request->getUser();

    $credential = id(new PassphraseCredentialQuery())
      ->setViewer($viewer)
      ->withIDs(array($this->id))
      ->executeOne();
    if (!$credential) {
      return new Aphront404Response();
    }

    $type = PassphraseCredentialType::getTypeByConstant(
      $credential->getCredentialType());
    if (!$type) {
      throw new Exception(pht('Credential has invalid type "%s"!', $type));
    }

    $xactions = id(new PassphraseCredentialTransactionQuery())
      ->setViewer($viewer)
      ->withObjectPHIDs(array($credential->getPHID()))
      ->execute();

    $engine = id(new PhabricatorMarkupEngine())
      ->setViewer($viewer);

    $timeline = id(new PhabricatorApplicationTransactionView())
      ->setUser($viewer)
      ->setObjectPHID($credential->getPHID())
      ->setTransactions($xactions);

    $title = pht('%s %s', 'K'.$credential->getID(), $credential->getName());
    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addCrumb(
      id(new PhabricatorCrumbView())
        ->setName('K'.$credential->getID()));

    $header = $this->buildHeaderView($credential);
    $actions = $this->buildActionView($credential);
    $properties = $this->buildPropertyView($credential, $type, $actions);

    $box = id(new PHUIObjectBoxView())
      ->setHeader($header)
      ->addPropertyList($properties);

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $box,
        $timeline,
      ),
      array(
        'title' => $title,
        'device' => true,
      ));
  }

  private function buildHeaderView(PassphraseCredential $credential) {
    $viewer = $this->getRequest()->getUser();

    $header = id(new PHUIHeaderView())
      ->setUser($viewer)
      ->setHeader($credential->getName())
      ->setPolicyObject($credential);

    if ($credential->getIsDestroyed()) {
      $header->setStatus('reject', 'red', pht('Destroyed'));
    }

    return $header;
  }

  private function buildActionView(PassphraseCredential $credential) {
    $viewer = $this->getRequest()->getUser();

    $id = $credential->getID();

    $actions = id(new PhabricatorActionListView())
      ->setObjectURI('/K'.$id)
      ->setUser($viewer);

    $can_edit = PhabricatorPolicyFilter::hasCapability(
      $viewer,
      $credential,
      PhabricatorPolicyCapability::CAN_EDIT);

    $actions->addAction(
      id(new PhabricatorActionView())
        ->setName(pht('Edit Credential'))
        ->setIcon('edit')
        ->setHref($this->getApplicationURI("edit/{$id}/"))
        ->setDisabled(!$can_edit)
        ->setWorkflow(!$can_edit));

    if (!$credential->getIsDestroyed()) {
      $actions->addAction(
        id(new PhabricatorActionView())
          ->setName(pht('Destroy Credential'))
          ->setIcon('delete')
          ->setHref($this->getApplicationURI("destroy/{$id}/"))
          ->setDisabled(!$can_edit)
          ->setWorkflow(true));

      $actions->addAction(
        id(new PhabricatorActionView())
          ->setName(pht('Show Secret'))
          ->setIcon('preview')
          ->setHref($this->getApplicationURI("reveal/{$id}/"))
          ->setDisabled(!$can_edit)
          ->setWorkflow(true));
    }


    return $actions;
  }

  private function buildPropertyView(
    PassphraseCredential $credential,
    PassphraseCredentialType $type,
    PhabricatorActionListView $actions) {
    $viewer = $this->getRequest()->getUser();

    $properties = id(new PHUIPropertyListView())
      ->setUser($viewer)
      ->setObject($credential)
      ->setActionList($actions);

    $properties->addProperty(
      pht('Credential Type'),
      $type->getCredentialTypeName());

    $descriptions = PhabricatorPolicyQuery::renderPolicyDescriptions(
      $viewer,
      $credential);

    $properties->addProperty(
      pht('Editable By'),
      $descriptions[PhabricatorPolicyCapability::CAN_EDIT]);

    $properties->addProperty(
      pht('Username'),
      $credential->getUsername());

    $description = $credential->getDescription();
    if (strlen($description)) {
      $properties->addSectionHeader(
        pht('Description'),
        PHUIPropertyListView::ICON_SUMMARY);
      $properties->addTextContent(
        PhabricatorMarkupEngine::renderOneObject(
          id(new PhabricatorMarkupOneOff())
            ->setContent($description),
          'default',
          $viewer));
    }

    return $properties;
  }

}
