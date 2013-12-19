<?php

/**
 * @group countdown
 */
final class PhabricatorCountdownViewController
  extends PhabricatorCountdownController {

  private $id;

  public function willProcessRequest(array $data) {
    $this->id = $data['id'];
  }


  public function processRequest() {

    $request = $this->getRequest();
    $user = $request->getUser();

    $countdown = id(new PhabricatorCountdownQuery())
      ->setViewer($user)
      ->withIDs(array($this->id))
      ->executeOne();
    if (!$countdown) {
      return new Aphront404Response();
    }

    $countdown_view = id(new PhabricatorCountdownView())
      ->setUser($user)
      ->setCountdown($countdown)
      ->setHeadless(true);

    $id = $countdown->getID();
    $title = $countdown->getTitle();

    $crumbs = $this
      ->buildApplicationCrumbs()
      ->addTextCrumb("C{$id}");

    $header = id(new PHUIHeaderView())
      ->setHeader($title)
      ->setUser($user)
      ->setPolicyObject($countdown);

    $actions = $this->buildActionListView($countdown);
    $properties = $this->buildPropertyListView($countdown, $actions);

    $object_box = id(new PHUIObjectBoxView())
      ->setHeader($header)
      ->addPropertyList($properties);

    $content = array(
      $crumbs,
      $object_box,
      $countdown_view,
    );

    return $this->buildApplicationPage(
      $content,
      array(
        'title' => $title,
        'device' => true,
      ));
  }

  private function buildActionListView(PhabricatorCountdown $countdown) {
    $request = $this->getRequest();
    $viewer = $request->getUser();

    $id = $countdown->getID();

    $view = id(new PhabricatorActionListView())
      ->setUser($viewer);

    $can_edit = PhabricatorPolicyFilter::hasCapability(
      $viewer,
      $countdown,
      PhabricatorPolicyCapability::CAN_EDIT);

    $view->addAction(
      id(new PhabricatorActionView())
        ->setIcon('edit')
        ->setName(pht('Edit Countdown'))
        ->setHref($this->getApplicationURI("edit/{$id}/"))
        ->setDisabled(!$can_edit)
        ->setWorkflow(!$can_edit));

    $view->addAction(
      id(new PhabricatorActionView())
        ->setIcon('delete')
        ->setName(pht('Delete Countdown'))
        ->setHref($this->getApplicationURI("delete/{$id}/"))
        ->setDisabled(!$can_edit)
        ->setWorkflow(true));

    return $view;
  }

  private function buildPropertyListView(
    PhabricatorCountdown $countdown,
    PhabricatorActionListView $actions) {

    $request = $this->getRequest();
    $viewer = $request->getUser();

    $this->loadHandles(array($countdown->getAuthorPHID()));

    $view = id(new PHUIPropertyListView())
      ->setUser($viewer)
      ->setActionList($actions);

    $view->addProperty(
      pht('Author'),
      $this->getHandle($countdown->getAuthorPHID())->renderLink());

    return $view;
  }

}
