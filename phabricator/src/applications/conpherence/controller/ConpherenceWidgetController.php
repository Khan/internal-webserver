<?php

/**
 * @group conpherence
 */
final class ConpherenceWidgetController extends
  ConpherenceController {

  private $conpherenceID;
  private $conpherence;
  private $userPreferences;

  public function setUserPreferences(PhabricatorUserPreferences $pref) {
    $this->userPreferences = $pref;
    return $this;
  }
  public function getUserPreferences() {
    return $this->userPreferences;
  }

  public function setConpherence(ConpherenceThread $conpherence) {
    $this->conpherence = $conpherence;
    return $this;
  }
  public function getConpherence() {
    return $this->conpherence;
  }

  public function setConpherenceID($conpherence_id) {
    $this->conpherenceID = $conpherence_id;
    return $this;
  }
  public function getConpherenceID() {
    return $this->conpherenceID;
  }

  public function willProcessRequest(array $data) {
    $this->setConpherenceID(idx($data, 'id'));
  }

  public function processRequest() {
    $request = $this->getRequest();
    $user = $request->getUser();

    $conpherence_id = $this->getConpherenceID();
    if (!$conpherence_id) {
      return new Aphront404Response();
    }
    $conpherence = id(new ConpherenceThreadQuery())
      ->setViewer($user)
      ->withIDs(array($conpherence_id))
      ->needWidgetData(true)
      ->executeOne();
    $this->setConpherence($conpherence);

    $this->setUserPreferences($user->loadPreferences());

    $widgets = $this->renderWidgetPaneContent();
    $content = $widgets;
    return id(new AphrontAjaxResponse())->setContent($content);
  }

  private function renderWidgetPaneContent() {
    require_celerity_resource('conpherence-widget-pane-css');
    require_celerity_resource('sprite-conpherence-css');
    $conpherence = $this->getConpherence();

    $widgets = array();
    $widgets[] = phutil_tag(
      'div',
      array(
        'class' => 'widgets-header',
      ),
      id(new PhabricatorActionHeaderView())
      ->setHeaderColor(PhabricatorActionHeaderView::HEADER_GREY)
      ->setHeaderTitle('')
      ->setHeaderHref('#')
      ->setDropdown(true)
      ->addHeaderSigil('widgets-selector'));
    $user = $this->getRequest()->getUser();
    // now the widget bodies
    $widgets[] = javelin_tag(
      'div',
      array(
        'class' => 'widgets-body',
        'id' => 'widgets-people',
        'sigil' => 'widgets-people',
      ),
      id(new ConpherencePeopleWidgetView())
      ->setUser($user)
      ->setConpherence($conpherence)
      ->setUpdateURI($this->getWidgetURI()));
    $widgets[] = javelin_tag(
      'div',
      array(
        'class' => 'widgets-body',
        'id' => 'widgets-files',
        'sigil' => 'widgets-files',
        'style' => 'display: none;'
      ),
      id(new ConpherenceFileWidgetView())
      ->setUser($user)
      ->setConpherence($conpherence)
      ->setUpdateURI($this->getWidgetURI()));
    $widgets[] = phutil_tag(
      'div',
      array(
        'class' => 'widgets-body',
        'id' => 'widgets-calendar',
        'style' => 'display: none;'
      ),
      $this->renderCalendarWidgetPaneContent());
    $widgets[] = phutil_tag(
      'div',
      array(
        'class' => 'widgets-body',
        'id' => 'widgets-settings',
        'style' => 'display: none'
      ),
      $this->renderSettingsWidgetPaneContent());

    // without this implosion we get "," between each element in our widgets
    // array
    return array('widgets' => phutil_implode_html('', $widgets));
  }

  private function renderSettingsWidgetPaneContent() {
    $user = $this->getRequest()->getUser();
    $conpherence = $this->getConpherence();
    $participants = $conpherence->getParticipants();
    $participant = $participants[$user->getPHID()];
    $default = ConpherenceSettings::EMAIL_ALWAYS;
    $preference = $this->getUserPreferences();
    if ($preference) {
      $default = $preference->getPreference(
        PhabricatorUserPreferences::PREFERENCE_CONPH_NOTIFICATIONS,
        ConpherenceSettings::EMAIL_ALWAYS);
    }
    $settings = $participant->getSettings();
    $notifications = idx(
      $settings,
      'notifications',
      $default);
    $options = id(new AphrontFormRadioButtonControl())
      ->addButton(
        ConpherenceSettings::EMAIL_ALWAYS,
        ConpherenceSettings::getHumanString(
          ConpherenceSettings::EMAIL_ALWAYS),
        '')
      ->addButton(
        ConpherenceSettings::NOTIFICATIONS_ONLY,
        ConpherenceSettings::getHumanString(
          ConpherenceSettings::NOTIFICATIONS_ONLY),
        '')
      ->setName('notifications')
      ->setValue($notifications);

    $layout = array(
      $options,
      phutil_tag(
        'input',
        array(
          'type' => 'hidden',
          'name' => 'action',
          'value' => 'notifications'
        )),
      phutil_tag(
        'button',
        array(
          'type' => 'submit',
          'class' => 'notifications-update',
        ),
        pht('Save'))
    );

    return phabricator_form(
      $user,
      array(
        'method' => 'POST',
        'action' => $this->getWidgetURI(),
        'sigil' => 'notifications-update',
      ),
      $layout);
  }

  private function renderCalendarWidgetPaneContent() {
    $user = $this->getRequest()->getUser();

    $conpherence = $this->getConpherence();
    $participants = $conpherence->getParticipants();
    $widget_data = $conpherence->getWidgetData();
    $statuses = $widget_data['statuses'];
    $handles = $conpherence->getHandles();
    $content = array();
    $layout = id(new AphrontMultiColumnView())
      ->setFluidLayout(true);
    $timestamps = $this->getCalendarWidgetWeekTimestamps();
    $today = $timestamps['today'];
    $weekstamps = $timestamps['weekstamps'];
    $one_day = 24 * 60 * 60;
    foreach ($weekstamps as $time => $day) {
      // build a header for the new day
      $content[] = phutil_tag(
        'div',
        array(
          'class' => 'day-header'
        ),
        array(
          phutil_tag(
            'div',
            array(
              'class' => 'day-name'
            ),
            $day->format('l')),
          phutil_tag(
            'div',
            array(
              'class' => 'day-date'
            ),
            $day->format('m/d/y'))
          ));

      $week_day_number = $day->format('w');


      $day->setTime(0, 0, 0);
      $epoch_start = $day->format('U');
      $next_day = clone $day;
      $next_day->modify('+1 day');
      $epoch_end = $next_day->format('U');

      $first_status_of_the_day = true;
      $statuses_of_the_day = array();
      // keep looking through statuses where we last left off
      foreach ($statuses as $status) {
        if ($status->getDateFrom() >= $epoch_end) {
          // This list is sorted, so we can stop looking.
          break;
        }

        if ($status->getDateFrom() < $epoch_end &&
            $status->getDateTo() > $epoch_start) {
          if (!$first_status_of_the_day) {
            $content[] = phutil_tag(
              'div',
              array(
                'class' => 'divider'
              ),
              '');
          }
          $statuses_of_the_day[$status->getUserPHID()] = $status;
          $timespan = $status->getDateTo() - $status->getDateFrom();
          if ($timespan > $one_day) {
            $time_str = 'm/d';
          } else {
            $time_str = 'h:i A';
          }
          $epoch_range = phabricator_format_local_time(
            $status->getDateFrom(),
            $user,
            $time_str) . ' - ' . phabricator_format_local_time(
              $status->getDateTo(),
              $user,
              $time_str);

          $secondary_info = pht('%s, %s',
            $handles[$status->getUserPHID()]->getName(), $epoch_range);

          $content[] = phutil_tag(
            'div',
            array(
              'class' => 'pm user-status '.$status->getTextStatus(),
            ),
            array(
              phutil_tag(
                'div',
                array(
                  'class' => 'icon',
                ),
                ''),
              phutil_tag(
                'div',
                array(
                  'class' => 'description'
                ),
                array(
                  $status->getTerseSummary($user),
                  phutil_tag(
                    'div',
                    array(
                      'class' => 'participant'
                    ),
                    $secondary_info)))
              ));
          $first_status_of_the_day = false;
        } else {
          $content[] = phutil_tag(
            'div',
            array('class' => 'no-events pmt pml'),
            pht('No Events Scheduled.'));
        }
      }

      // we didn't get a status on this day so add a spacer
      if ($first_status_of_the_day) {
        $content[] = phutil_tag(
          'div',
          array(
            'class' => 'spacer'
          ),
          '');
      }
      if ($week_day_number > 0 && $week_day_number < 6) {
        if ($week_day_number == $today->format('w')) {
          $active_class = '-active';
        } else {
          $active_class = '';
        }
        $inner_layout = array();
        foreach ($participants as $phid => $participant) {
          $status = idx($statuses_of_the_day, $phid, false);
          if ($status) {
            $inner_layout[] = phutil_tag(
              'div',
              array(
                'class' => $status->getTextStatus()
              ),
              '');
          } else {
            $inner_layout[] = phutil_tag(
              'div',
              array(
                'class' => 'present'
              ),
              '');
          }
        }
        $layout->addColumn(
          phutil_tag(
            'div',
            array(
              'class' => 'day-column'.$active_class
            ),
            array(
              phutil_tag(
                'div',
                array(
                  'class' => 'day-name'
                ),
                $day->format('D')),
              phutil_tag(
                'div',
                array(
                  'class' => 'day-number',
                ),
                $day->format('j')),
              $inner_layout
              )));
      }
    }

    return
      array(
        $layout,
        $content
      );
  }

  private function getCalendarWidgetWeekTimestamps() {
    $user = $this->getRequest()->getUser();
    $timezone = new DateTimeZone($user->getTimezoneIdentifier());

    $today = id(new DateTime('now', $timezone));
    $monday = clone $today;
    $monday
      ->modify('+1 day')
      ->modify('last monday');
    $timestamps = array();
    for ($day = 0; $day < 7; $day++) {
      $timestamp = clone $monday;
      $timestamps[] = $timestamp->modify(sprintf('+%d days', $day));
    }

    return array(
      'today' => $today,
      'weekstamps' => $timestamps
    );
  }

  private function getWidgetURI() {
    $conpherence = $this->getConpherence();
    return $this->getApplicationURI('update/'.$conpherence->getID().'/');
  }

}
