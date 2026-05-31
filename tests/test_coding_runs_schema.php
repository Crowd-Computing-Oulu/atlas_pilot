<?php
require __DIR__ . '/../app/db.php';
$tmp = '/tmp/atlas_runs_schema_test.db';
@unlink($tmp);
$db = new SQLite3($tmp);
$db->enableExceptions(true);
$db->exec('PRAGMA foreign_keys=OFF');
init_schema($db);

// coding_runs table exists with the expected columns
$cols = [];
$r = $db->query("PRAGMA table_info(coding_runs)");
while ($row = $r->fetchArray(SQLITE3_ASSOC)) $cols[$row['name']] = true;
foreach (['id','label','model','instructions','output_contract','full_prompt','temperature','status','created_at'] as $c) {
    assert(isset($cols[$c]), "coding_runs missing column $c");
}

// codings.run_id exists
$ccols = [];
$r = $db->query("PRAGMA table_info(codings)");
while ($row = $r->fetchArray(SQLITE3_ASSOC)) $ccols[$row['name']] = true;
assert(isset($ccols['run_id']), 'codings missing run_id');

echo "Task1 schema OK\n";
@unlink($tmp);
