<?php

/**
 * @group conpherence
 */
final class ConpherenceNotificationPanelController
  extends ConpherenceController {

  public function processRequest() {

    $request = $this->getRequest();
    $user = $request->getUser();
    $conpherences = array();
    $unread_status = ConpherenceParticipationStatus::BEHIND;

    $participant_data = id(new ConpherenceParticipantQuery())
      ->withParticipantPHIDs(array($user->getPHID()))
      ->setLimit(5)
      ->execute();

    if ($participant_data) {
      $conpherences = id(new ConpherenceThreadQuery())
        ->setViewer($user)
        ->withPHIDs(array_keys($participant_data))
        ->needParticipantCache(true)
        ->execute();
    }

    if ($conpherences) {
      require_celerity_resource('conpherence-notification-css');
      // re-order the conpherences based on participation data
      $conpherences = array_select_keys(
        $conpherences, array_keys($participant_data));
      $view = new AphrontNullView();
      foreach ($conpherences as $conpherence) {
        $p_data = $participant_data[$conpherence->getPHID()];
        $d_data = $conpherence->getDisplayData($user);
        $classes = array(
          'phabricator-notification',
          'conpherence-notification',
        );

        if ($p_data->getParticipationStatus() == $unread_status) {
          $classes[] = 'phabricator-notification-unread';
        }
        $uri = $this->getApplicationURI($conpherence->getID().'/');
        $title = $d_data['title'];
        $subtitle = $d_data['subtitle'];
        $unread_count = $d_data['unread_count'];
        $epoch = $d_data['epoch'];
        $image = $d_data['image'];

        $msg_view = id(new ConpherenceMenuItemView())
          ->setUser($user)
          ->setTitle($title)
          ->setSubtitle($subtitle)
          ->setHref($uri)
          ->setEpoch($epoch)
          ->setImageURI($image)
          ->setUnreadCount($unread_count);

        $view->appendChild(javelin_tag(
          'div',
          array(
            'class' => implode(' ', $classes),
            'sigil' => 'notification',
            'meta' => array(
              'href' => $uri,
            ),
          ),
          $msg_view));
      }
      $content = $view->render();
    } else {
      $content = hsprintf(
        '<div class="phabricator-notification no-notifications">%s</div>',
        pht('You have no messages.'));
    }

    $content = hsprintf(
      '<div class="phabricator-notification-header">%s</div>'.
      '%s'.
      '<div class="phabricator-notification-view-all">%s</div>',
      pht('Messages'),
      $content,
      phutil_tag(
        'a',
        array(
          'href' => '/conpherence/',
        ),
        'View All Conpherences'));

    $unread = id(new ConpherenceParticipantCountQuery())
      ->withParticipantPHIDs(array($user->getPHID()))
      ->withParticipationStatus($unread_status)
      ->execute();
    $unread_count = idx($unread, $user->getPHID(), 0);

    $json = array(
      'content' => $content,
      'number'  => (int)$unread_count,
    );

    return id(new AphrontAjaxResponse())->setContent($json);
  }
}
