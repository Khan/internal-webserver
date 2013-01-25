<?php

/**
 * @group conpherence
 */
final class ConpherenceThreadQuery
  extends PhabricatorCursorPagedPolicyAwareQuery {

  private $phids;
  private $ids;
  private $needWidgetData;

  public function needWidgetData($need_widget_data) {
    $this->needWidgetData = $need_widget_data;
    return $this;
  }

  public function withIDs(array $ids) {
    $this->ids = $ids;
    return $this;
  }

  public function withPHIDs(array $phids) {
    $this->phids = $phids;
    return $this;
  }

  public function loadPage() {
    $table = new ConpherenceThread();
    $conn_r = $table->establishConnection('r');

    $data = queryfx_all(
      $conn_r,
      'SELECT conpherence_thread.* FROM %T conpherence_thread %Q %Q %Q',
      $table->getTableName(),
      $this->buildWhereClause($conn_r),
      $this->buildOrderClause($conn_r),
      $this->buildLimitClause($conn_r));

    $conpherences = $table->loadAllFromArray($data);

    if ($conpherences) {
      $conpherences = mpull($conpherences, null, 'getPHID');
      $this->loadParticipants($conpherences);
      $this->loadTransactionsAndHandles($conpherences);
      $this->loadFilePHIDs($conpherences);
      if ($this->needWidgetData) {
        $this->loadWidgetData($conpherences);
      }
    }

    return $conpherences;
  }

  protected function buildWhereClause($conn_r) {
    $where = array();

    $where[] = $this->buildPagingClause($conn_r);

    if ($this->ids) {
      $where[] = qsprintf(
        $conn_r,
        'id IN (%Ld)',
        $this->ids);
    }

    if ($this->phids) {
      $where[] = qsprintf(
        $conn_r,
        'phid IN (%Ls)',
        $this->phids);
    }

    return $this->formatWhereClause($where);
  }

  private function loadParticipants(array $conpherences) {
    $participants = id(new ConpherenceParticipant())
      ->loadAllWhere('conpherencePHID IN (%Ls)', array_keys($conpherences));
    $map = mgroup($participants, 'getConpherencePHID');
    foreach ($map as $conpherence_phid => $conpherence_participants) {
      $current_conpherence = $conpherences[$conpherence_phid];
      $conpherence_participants = mpull(
        $conpherence_participants,
        null,
        'getParticipantPHID'
      );
      $current_conpherence->attachParticipants($conpherence_participants);
    }

    return $this;
  }

  private function loadTransactionsAndHandles(array $conpherences) {
    $transactions = id(new ConpherenceTransactionQuery())
      ->setViewer($this->getViewer())
      ->withObjectPHIDs(array_keys($conpherences))
      ->needHandles(true)
      ->loadPage();
    $transactions = mgroup($transactions, 'getObjectPHID');
    foreach ($conpherences as $phid => $conpherence) {
      $current_transactions = $transactions[$phid];
      $handles = array();
      foreach ($current_transactions as $transaction) {
        $handles += $transaction->getHandles();
      }
      $conpherence->attachHandles($handles);
      $conpherence->attachTransactions($transactions[$phid]);
    }
    return $this;
  }

  private function loadFilePHIDs(array $conpherences) {
    $edge_type = PhabricatorEdgeConfig::TYPE_OBJECT_HAS_FILE;
    $file_edges = id(new PhabricatorEdgeQuery())
      ->withSourcePHIDs(array_keys($conpherences))
      ->withEdgeTypes(array($edge_type))
      ->execute();
    foreach ($file_edges as $conpherence_phid => $data) {
      $conpherence = $conpherences[$conpherence_phid];
      $conpherence->attachFilePHIDs(array_keys($data[$edge_type]));
    }
    return $this;
  }

  private function loadWidgetData(array $conpherences) {
    $participant_phids = array();
    $file_phids = array();
    foreach ($conpherences as $conpherence) {
      $participant_phids[] = array_keys($conpherence->getParticipants());
      $file_phids[] = $conpherence->getFilePHIDs();
    }
    $participant_phids = array_mergev($participant_phids);
    $file_phids = array_mergev($file_phids);

    // open tasks of all participants
    $tasks = id(new ManiphestTaskQuery())
      ->withOwners($participant_phids)
      ->withStatus(ManiphestTaskQuery::STATUS_OPEN)
      ->execute();
    $tasks = mgroup($tasks, 'getOwnerPHID');

    // statuses of everyone currently in the conpherence
    // until the beginning of the next work week.
    // NOTE: this is a bit boring on the weekends.
    $end_of_week = phabricator_format_local_time(
      strtotime('Monday midnight'),
      $this->getViewer(),
      'U'
    );
    $statuses = id(new PhabricatorUserStatus())
      ->loadAllWhere(
        'userPHID in (%Ls) AND dateTo <= %d',
        $participant_phids,
        $end_of_week
      );
    $statuses = mgroup($statuses, 'getUserPHID');

    // attached files
    $files = array();
    if ($file_phids) {
      $files = id(new PhabricatorFileQuery())
        ->setViewer($this->getViewer())
        ->withPHIDs($file_phids)
        ->execute();
      $files = mpull($files, null, 'getPHID');
    }

    foreach ($conpherences as $phid => $conpherence) {
      $participant_phids = array_keys($conpherence->getParticipants());
      $widget_data = array(
        'tasks' => array_select_keys($tasks, $participant_phids),
        'statuses' => array_select_keys($statuses, $participant_phids),
        'files' => array_select_keys($files, $conpherence->getFilePHIDs()),
      );
      $conpherence->attachWidgetData($widget_data);
    }

    return $this;
  }

}
