<?php

final class PhabricatorOAuthClientViewController
  extends PhabricatorOAuthClientBaseController {

  public function processRequest() {
    $request = $this->getRequest();
    $viewer = $request->getUser();

    $client = id(new PhabricatorOAuthServerClientQuery())
      ->setViewer($viewer)
      ->withPHIDs(array($this->getClientPHID()))
      ->executeOne();
    if (!$client) {
      return new Aphront404Response();
    }

    $header = $this->buildHeaderView($client);
    $actions = $this->buildActionView($client);
    $properties = $this->buildPropertyListView($client);
    $properties->setActionList($actions);

    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addTextCrumb($client->getName());

    $box = id(new PHUIObjectBoxView())
      ->setHeader($header)
      ->addPropertyList($properties);

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $box,
      ),
      array(
        'title' => pht('OAuth Application: %s', $client->getName()),
        'device' => true,
      ));
  }

  private function buildHeaderView(PhabricatorOAuthServerClient $client) {
    $viewer = $this->getRequest()->getUser();

    $header = id(new PHUIHeaderView())
      ->setUser($viewer)
      ->setHeader(pht('OAuth Application: %s', $client->getName()))
      ->setPolicyObject($client);

    return $header;
  }

  private function buildActionView(PhabricatorOAuthServerClient $client) {
    $viewer = $this->getRequest()->getUser();

    $can_edit = PhabricatorPolicyFilter::hasCapability(
      $viewer,
      $client,
      PhabricatorPolicyCapability::CAN_EDIT);

    $authorization = id(new PhabricatorOAuthClientAuthorizationQuery())
      ->setViewer($viewer)
      ->withUserPHIDs(array($viewer->getPHID()))
      ->withClientPHIDs(array($client->getPHID()))
      ->executeOne();
    $is_authorized = (bool)$authorization;

    $view = id(new PhabricatorActionListView())
      ->setUser($viewer);

    $view->addAction(
      id(new PhabricatorActionView())
        ->setName(pht('Edit Application'))
        ->setIcon('edit')
        ->setWorkflow(!$can_edit)
        ->setDisabled(!$can_edit)
        ->setHref($client->getEditURI()));

    $view->addAction(
      id(new PhabricatorActionView())
        ->setName(pht('Delete Application'))
        ->setIcon('delete')
        ->setWorkflow(true)
        ->setDisabled(!$can_edit)
        ->setHref($client->getDeleteURI()));

    $view->addAction(
      id(new PhabricatorActionView())
        ->setName(pht('Create Test Authorization'))
        ->setIcon('wrench')
        ->setWorkflow(true)
        ->setDisabled($is_authorized)
        ->setHref($this->getApplicationURI('test/'.$client->getID().'/')));

    return $view;
  }

  private function buildPropertyListView(PhabricatorOAuthServerClient $client) {
    $viewer = $this->getRequest()->getUser();

    $view = id(new PHUIPropertyListView())
      ->setUser($viewer);

    $view->addProperty(
      pht('Client ID'),
      $client->getPHID());

    $view->addProperty(
      pht('Client Secret'),
      $client->getSecret());

    $view->addProperty(
      pht('Redirect URI'),
      $client->getRedirectURI());

    $view->addProperty(
      pht('Created'),
      phabricator_datetime($client->getDateCreated(), $viewer));

    return $view;
  }
}
