<?php

final class PhabricatorPeopleProfileController
  extends PhabricatorPeopleController {

  private $username;

  public function shouldRequireAdmin() {
    return false;
  }

  public function willProcessRequest(array $data) {
    $this->username = idx($data, 'username');
  }

  public function processRequest() {
    $viewer = $this->getRequest()->getUser();

    $user = id(new PhabricatorPeopleQuery())
      ->setViewer($viewer)
      ->withUsernames(array($this->username))
      ->needProfileImage(true)
      ->executeOne();
    if (!$user) {
      return new Aphront404Response();
    }

    require_celerity_resource('phabricator-profile-css');

    $profile = $user->loadUserProfile();
    $username = phutil_escape_uri($user->getUserName());

    $picture = $user->loadProfileImageURI();

    $header = id(new PHUIHeaderView())
      ->setHeader($user->getUserName().' ('.$user->getRealName().')')
      ->setSubheader($profile->getTitle())
      ->setImage($picture);

    $actions = id(new PhabricatorActionListView())
      ->setObject($user)
      ->setObjectURI($this->getRequest()->getRequestURI())
      ->setUser($viewer);

    $can_edit = ($user->getPHID() == $viewer->getPHID());

    $actions->addAction(
      id(new PhabricatorActionView())
        ->setIcon('edit')
        ->setName(pht('Edit Profile'))
        ->setHref($this->getApplicationURI('editprofile/'.$user->getID().'/'))
        ->setDisabled(!$can_edit)
        ->setWorkflow(!$can_edit));

    $actions->addAction(
      id(new PhabricatorActionView())
        ->setIcon('image')
        ->setName(pht('Edit Profile Picture'))
        ->setHref($this->getApplicationURI('picture/'.$user->getID().'/'))
        ->setDisabled(!$can_edit)
        ->setWorkflow(!$can_edit));

    if ($viewer->getIsAdmin()) {
      $actions->addAction(
        id(new PhabricatorActionView())
          ->setIcon('blame')
          ->setName(pht('Administrate User'))
          ->setHref($this->getApplicationURI('edit/'.$user->getID().'/')));
    }

    $properties = $this->buildPropertyView($user, $actions);

    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addTextCrumb($user->getUsername());
    $feed = $this->renderUserFeed($user);
    $calendar = $this->renderUserCalendar($user);
    $activity = phutil_tag(
      'div',
      array(
        'class' => 'profile-activity-view grouped'
      ),
      array(
        $calendar,
        $feed
      ));

    $object_box = id(new PHUIObjectBoxView())
      ->setHeader($header)
      ->addPropertyList($properties);

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $object_box,
        $activity,
      ),
      array(
        'title' => $user->getUsername(),
        'device' => true,
      ));
  }

  private function buildPropertyView(
    PhabricatorUser $user,
    PhabricatorActionListView $actions) {

    $viewer = $this->getRequest()->getUser();
    $view = id(new PHUIPropertyListView())
      ->setUser($viewer)
      ->setObject($user)
      ->setActionList($actions);

    $field_list = PhabricatorCustomField::getObjectFields(
      $user,
      PhabricatorCustomField::ROLE_VIEW);
    $field_list->appendFieldsToPropertyList($user, $viewer, $view);

    return $view;
  }

  private function renderUserFeed(PhabricatorUser $user) {
    $viewer = $this->getRequest()->getUser();

    $query = new PhabricatorFeedQuery();
    $query->setFilterPHIDs(
      array(
        $user->getPHID(),
      ));
    $query->setLimit(100);
    $query->setViewer($viewer);
    $stories = $query->execute();

    $builder = new PhabricatorFeedBuilder($stories);
    $builder->setUser($viewer);
    $builder->setShowHovercards(true);
    $view = $builder->buildView();

    return phutil_tag_div(
      'profile-feed',
      $view->render());
  }

  private function renderUserCalendar(PhabricatorUser $user) {
    $viewer = $this->getRequest()->getUser();
    $epochs = CalendarTimeUtil::getCalendarEventEpochs(
      $viewer,
      'today',
       7);
    $start_epoch = $epochs['start_epoch'];
    $end_epoch = $epochs['end_epoch'];
    $statuses = id(new PhabricatorCalendarEventQuery())
      ->setViewer($viewer)
      ->withInvitedPHIDs(array($user->getPHID()))
      ->withDateRange($start_epoch, $end_epoch)
      ->execute();

    $timestamps = CalendarTimeUtil::getCalendarWeekTimestamps(
      $viewer);
    $today = $timestamps['today'];
    $epoch_stamps = $timestamps['epoch_stamps'];
    $events = array();

    foreach ($epoch_stamps as $day) {
      $epoch_start = $day->format('U');
      $next_day = clone $day;
      $next_day->modify('+1 day');
      $epoch_end = $next_day->format('U');

      foreach ($statuses as $status) {
        if ($status->getDateTo() < $epoch_start) {
          continue;
        }
        if ($status->getDateFrom() >= $epoch_end) {
          continue;
        }

        $event = new AphrontCalendarEventView();
        $event->setEpochRange($status->getDateFrom(), $status->getDateTo());

        $status_text = $status->getHumanStatus();
        $event->setUserPHID($status->getUserPHID());
        $event->setName($status_text);
        $event->setDescription($status->getDescription());
        $event->setEventID($status->getID());
        $events[$epoch_start][] = $event;
      }
    }

    $week = array();
    foreach ($epoch_stamps as $day) {
      $epoch = $day->format('U');
      $headertext = phabricator_format_local_time($epoch, $user, 'l, M d');

      $list = new PHUICalendarListView();
      $list->setUser($viewer);
      $list->showBlankState(true);
      if (isset($events[$epoch])) {
        foreach ($events[$epoch] as $event) {
          $list->addEvent($event);
        }
      }

      $header = phutil_tag(
        'a',
        array(
          'href' => $this->getRequest()->getRequestURI().'calendar/'
        ),
        $headertext);

      $calendar = new PHUICalendarWidgetView();
      $calendar->setHeader($header);
      $calendar->setCalendarList($list);
      $week[] = $calendar;
    }

    return phutil_tag_div(
      'profile-calendar',
      $week);
  }
}
