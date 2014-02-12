<?php

$conn_w = id(new DifferentialRevision())->establishConnection('w');
$rows = new LiskRawMigrationIterator($conn_w, 'differential_comment');

$content_source = PhabricatorContentSource::newForSource(
  PhabricatorContentSource::SOURCE_LEGACY,
  array())->serialize();

echo "Migrating Differential comment text to modern storage...\n";
foreach ($rows as $row) {
  $id = $row['id'];
  echo "Migrating Differential comment {$id}...\n";
  if (!strlen($row['content'])) {
    echo "Comment has no text, continuing.\n";
    continue;
  }

  $revision = id(new DifferentialRevision())->load($row['revisionID']);
  if (!$revision) {
    echo "Comment has no valid revision, continuing.\n";
    continue;
  }

  $revision_phid = $revision->getPHID();

  $dst_table = 'differential_inline_comment';

  $xaction_phid = PhabricatorPHID::generateNewPHID(
    PhabricatorApplicationTransactionPHIDTypeTransaction::TYPECONST,
    DifferentialPHIDTypeRevision::TYPECONST);

  $comment_phid = PhabricatorPHID::generateNewPHID(
    PhabricatorPHIDConstants::PHID_TYPE_XCMT,
    DifferentialPHIDTypeRevision::TYPECONST);

  queryfx(
    $conn_w,
    'INSERT IGNORE INTO %T
      (phid, transactionPHID, authorPHID, viewPolicy, editPolicy,
        commentVersion, content, contentSource, isDeleted,
        dateCreated, dateModified, revisionPHID, changesetID,
        legacyCommentID)
      VALUES (%s, %s, %s, %s, %s,
        %d, %s, %s, %d,
        %d, %d, %s, %nd,
        %d)',
    'differential_transaction_comment',

    // phid, transactionPHID, authorPHID, viewPolicy, editPolicy
    $comment_phid,
    $xaction_phid,
    $row['authorPHID'],
    'public',
    $row['authorPHID'],

    // commentVersion, content, contentSource, isDeleted
    1,
    $row['content'],
    $content_source,
    0,

    // dateCreated, dateModified, revisionPHID, changesetID, legacyCommentID
    $row['dateCreated'],
    $row['dateModified'],
    $revision_phid,
    null,
    $row['id']);
}

echo "Done.\n";
