<?php

final class PhabricatorDaemonLogEventViewController
  extends PhabricatorDaemonController {

  private $id;

  public function willProcessRequest(array $data) {
    $this->id = $data['id'];
  }

  public function processRequest() {
    $request = $this->getRequest();

    $event = id(new PhabricatorDaemonLogEvent())->load($this->id);
    if (!$event) {
      return new Aphront404Response();
    }

    $event_view = id(new PhabricatorDaemonLogEventsView())
      ->setEvents(array($event))
      ->setUser($request->getUser())
      ->setCombinedLog(true)
      ->setShowFullMessage(true);

    $log_panel = new AphrontPanelView();
    $log_panel->appendChild($event_view);
    $log_panel->setNoBackground();

    $daemon_id = $event->getLogID();

    $crumbs = $this->buildApplicationCrumbs()
      ->addTextCrumb(
        pht('Daemon %s', $daemon_id),
        $this->getApplicationURI("log/{$daemon_id}/"))
      ->addTextCrumb(pht('Event %s', $event->getID()));


    return $this->buildApplicationPage(
      array(
        $crumbs,
        $log_panel,
      ),
      array(
        'title' => pht('Combined Daemon Log'),
      ));
  }

}
