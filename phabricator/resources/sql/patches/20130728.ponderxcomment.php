<?php

$qtable = new PonderQuestionTransaction();
$atable = new PonderAnswerTransaction();

$conn_w = $qtable->establishConnection('w');
$conn_w->openTransaction();

echo "Migrating Ponder comments to ApplicationTransactions...\n";

$rows = new LiskRawMigrationIterator($conn_w, 'ponder_comment');
foreach ($rows as $row) {

  $id = $row['id'];
  echo "Migrating {$id}...\n";

  $type = phid_get_type($row['targetPHID']);
  switch ($type) {
    case PonderPHIDTypeQuestion::TYPECONST:
      $table_obj = $qtable;
      $comment_obj = new PonderQuestionTransactionComment();
      break;
    case PonderPHIDTypeAnswer::TYPECONST:
      $table_obj = $atable;
      $comment_obj = new PonderAnswerTransactionComment();
      break;
  }

  $comment_phid = PhabricatorPHID::generateNewPHID(
    PhabricatorApplicationTransactionPHIDTypeTransaction::TYPECONST,
    $type);

  $xaction_phid = PhabricatorPHID::generateNewPHID(
    PhabricatorApplicationTransactionPHIDTypeTransaction::TYPECONST,
    $type);

  queryfx(
    $conn_w,
    'INSERT INTO %T (phid, transactionPHID, authorPHID, viewPolicy, editPolicy,
        commentVersion, content, contentSource, isDeleted, dateCreated,
        dateModified)
      VALUES (%s, %s, %s, %s, %s, %d, %s, %s, %d, %d, %d)',
    $comment_obj->getTableName(),
    $comment_phid,
    $xaction_phid,
    $row['authorPHID'],
    'public',
    $row['authorPHID'],
    1,
    $row['content'],
    PhabricatorContentSource::newForSource(
      PhabricatorContentSource::SOURCE_LEGACY,
      array())->serialize(),
    0,
    $row['dateCreated'],
    $row['dateModified']);

  queryfx(
    $conn_w,
    'INSERT INTO %T (phid, authorPHID, objectPHID, viewPolicy, editPolicy,
        commentPHID, commentVersion, transactionType, oldValue, newValue,
        contentSource, metadata, dateCreated, dateModified)
      VALUES (%s, %s, %s, %s, %s, %s, %d, %s, %ns, %ns, %s, %s, %d, %d)',
    $table_obj->getTableName(),
    $xaction_phid,
    $row['authorPHID'],
    $row['targetPHID'],
    'public',
    $row['authorPHID'],
    $comment_phid,
    1,
    PhabricatorTransactions::TYPE_COMMENT,
    'null',
    'null',
    PhabricatorContentSource::newForSource(
      PhabricatorContentSource::SOURCE_LEGACY,
      array())->serialize(),
    '[]',
    $row['dateCreated'],
    $row['dateModified']);

}

$conn_w->saveTransaction();

echo "Done.\n";
