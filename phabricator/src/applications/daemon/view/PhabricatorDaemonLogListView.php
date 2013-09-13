<?php

final class PhabricatorDaemonLogListView extends AphrontView {

  private $daemonLogs;

  public function setDaemonLogs(array $daemon_logs) {
    assert_instances_of($daemon_logs, 'PhabricatorDaemonLog');
    $this->daemonLogs = $daemon_logs;
    return $this;
  }

  public function render() {
    $rows = array();

    if (!$this->user) {
      throw new Exception("Call setUser() before rendering!");
    }

    $list = id(new PHUIObjectItemListView());
    foreach ($this->daemonLogs as $log) {
      $id = $log->getID();
      $epoch = $log->getDateCreated();

      $item = id(new PHUIObjectItemView())
        ->setObjectName(pht("Daemon %s", $id))
        ->setHeader($log->getDaemon())
        ->setHref("/daemon/log/{$id}/")
        ->addIcon('none', phabricator_datetime($epoch, $this->user));

      $status = $log->getStatus();
      switch ($status) {
        case PhabricatorDaemonLog::STATUS_RUNNING:
          $item->setBarColor('green');
          $item->addAttribute(pht('This daemon is running.'));
          break;
        case PhabricatorDaemonLog::STATUS_DEAD:
          $item->setBarColor('red');
          $item->addAttribute(
            pht(
              'This daemon is lost or exited uncleanly, and is presumed '.
              'dead.'));
          $item->addIcon('delete', pht('Dead'));
          break;
        case PhabricatorDaemonLog::STATUS_EXITED:
          $item->setDisabled(true);
          $item->addAttribute(pht('This daemon exited cleanly.'));
          $item->addIcon('enable-grey', pht('Exited'));
          break;
        case PhabricatorDaemonLog::STATUS_WAIT:
          $item->setBarColor('blue');
          $item->addAttribute(
            pht(
              'This daemon encountered an error recently and is waiting a '.
              'moment to restart.'));
          $item->addIcon('perflab-grey', pht('Waiting'));
          break;
        case PhabricatorDaemonLog::STATUS_UNKNOWN:
        default:
          $item->setBarColor('orange');
          $item->addAttribute(
            pht(
              'This daemon has not reported its status recently. It may '.
              'have exited uncleanly.'));
          $item->addIcon('warning', pht('Unknown'));
          break;
      }

      $list->addItem($item);
    }

    return $list;
  }

}
