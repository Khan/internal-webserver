<?php

$status_map = array(
  0 => 'open',
  1 => 'resolved',
  2 => 'wontfix',
  3 => 'invalid',
  4 => 'duplicate',
  5 => 'spite',
);

$conn_w = id(new ManiphestTask())->establishConnection('w');

echo "Migrating tasks to new status constants...\n";
foreach (new LiskMigrationIterator(new ManiphestTask()) as $task) {
  $id = $task->getID();
  echo "Migrating T{$id}...\n";

  $status = $task->getStatus();
  if (isset($status_map[$status])) {
    queryfx(
      $conn_w,
      'UPDATE %T SET status = %s WHERE id = %d',
      $task->getTableName(),
      $status_map[$status],
      $id);
  }
}

echo "Done.\n";


echo "Migrating task transactions to new status constants...\n";
foreach (new LiskMigrationIterator(new ManiphestTransaction()) as $xaction) {
  $id = $xaction->getID();
  echo "Migrating {$id}...\n";

  if ($xaction->getTransactionType() == ManiphestTransaction::TYPE_STATUS) {
    $old = $xaction->getOldValue();
    if ($old !== null && isset($status_map[$old])) {
      $old = $status_map[$old];
    }

    $new = $xaction->getNewValue();
    if (isset($status_map[$new])) {
      $new = $status_map[$new];
    }

    queryfx(
      $conn_w,
      'UPDATE %T SET oldValue = %s, newValue = %s WHERE id = %d',
      $xaction->getTableName(),
      json_encode($old),
      json_encode($new),
      $id);
  }
}
echo "Done.\n";

$conn_w = id(new PhabricatorSavedQuery())->establishConnection('w');

echo "Migrating searches to new status constants...\n";
foreach (new LiskMigrationIterator(new PhabricatorSavedQuery()) as $query) {
  $id = $query->getID();
  echo "Migrating {$id}...\n";

  if ($query->getEngineClassName() !== 'ManiphestTaskSearchEngine') {
    continue;
  }

  $params = $query->getParameters();
  $statuses = idx($params, 'statuses', array());
  if ($statuses) {
    $changed = false;
    foreach ($statuses as $key => $status) {
      if (isset($status_map[$status])) {
        $statuses[$key] = $status_map[$status];
        $changed = true;
      }
    }

    if ($changed) {
      $params['statuses'] = $statuses;

      queryfx(
        $conn_w,
        'UPDATE %T SET parameters = %s WHERE id = %d',
        $query->getTableName(),
        json_encode($params),
        $id);
    }
  }
}
echo "Done.\n";
