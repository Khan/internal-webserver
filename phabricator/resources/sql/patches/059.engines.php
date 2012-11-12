<?php

$conn = $schema_conn;

$tables = queryfx_all(
  $conn,
  "SELECT TABLE_SCHEMA db, TABLE_NAME tbl
    FROM information_schema.TABLES s
    WHERE s.TABLE_SCHEMA LIKE %>
    AND s.TABLE_NAME != 'search_documentfield'
    AND s.ENGINE != 'InnoDB'",
    '{$NAMESPACE}_');

if (!$tables) {
  return;
}

echo "There are ".count($tables)." tables using the MyISAM engine. These will ".
     "now be converted to InnoDB. This process may take a few minutes, please ".
     "be patient.\n";

foreach ($tables as $table) {
  $name = $table['db'].'.'.$table['tbl'];
  echo "Converting {$name}...\n";
  queryfx(
    $conn,
    "ALTER TABLE %T.%T ENGINE=InnoDB",
    $table['db'],
    $table['tbl']);
}
echo "Done!\n";
