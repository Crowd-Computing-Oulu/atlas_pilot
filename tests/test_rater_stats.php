<?php
require __DIR__ . '/../app/coding_stats.php';

function check(bool $cond, string $msg): void { if (!$cond) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); } }

// --- coding_median ---
check(coding_median([]) === null, 'median of empty is null');
check(coding_median([5]) === 5.0, 'median of one');
check(coding_median([3, 1, 2]) === 2.0, 'median odd');
check(coding_median([4, 1, 3, 2]) === 2.5, 'median even');
check(coding_median([10, null, 20]) === 15.0, 'median ignores null');

// --- rater_overview ---
$rows = [
    // spammer: 4 fast ratings, all technique_count 3
    ['rater_pid' => 'spam', 'coding_seconds' => 13, 'technique_count' => 3],
    ['rater_pid' => 'spam', 'coding_seconds' => 15, 'technique_count' => 3],
    ['rater_pid' => 'spam', 'coding_seconds' => 16, 'technique_count' => 3],
    ['rater_pid' => 'spam', 'coding_seconds' => 35, 'technique_count' => 2],
    // careful rater: slow, varied counts
    ['rater_pid' => 'good', 'coding_seconds' => 64, 'technique_count' => 1],
    ['rater_pid' => 'good', 'coding_seconds' => 84, 'technique_count' => 2],
    // rater with no timing captured (pre-timing rows)
    ['rater_pid' => 'old',  'coding_seconds' => null, 'technique_count' => 1],
    // a row with empty pid is ignored
    ['rater_pid' => '',     'coding_seconds' => 5, 'technique_count' => 3],
];
$o = rater_overview($rows, 20);

check(count($o) === 3, 'three raters (empty pid dropped), got ' . count($o));

// most suspicious first => spammer leads (3/4 fast = 0.75)
check($o[0]['pid'] === 'spam', 'spammer ranked first, got ' . $o[0]['pid']);
check($o[0]['n'] === 4, 'spammer n=4');
check($o[0]['n_fast'] === 3, 'spammer 3 fast (<20)');
check($o[0]['n_timed'] === 4, 'spammer 4 timed');
check(abs($o[0]['pct_fast'] - 0.75) < 1e-9, 'spammer pct_fast 0.75');
check($o[0]['median'] === 15.5, 'spammer median 15.5, got ' . var_export($o[0]['median'], true));
check($o[0]['min'] === 13, 'spammer min 13');
check($o[0]['tc'] === ['1' => 0, '2' => 1, '3' => 3], 'spammer tc dist');

// careful rater: 0 fast
$good = null; foreach ($o as $r) if ($r['pid'] === 'good') $good = $r;
check($good['n_fast'] === 0, 'good rater 0 fast');
check($good['pct_fast'] === 0.0, 'good rater pct_fast 0');
check($good['median'] === 74.0, 'good rater median 74');

// untimed rater sorts last and has null median, pct_fast 0
check($o[count($o) - 1]['pid'] === 'old', 'untimed rater last');
check($o[count($o) - 1]['median'] === null, 'untimed median null');
check($o[count($o) - 1]['n_timed'] === 0, 'untimed n_timed 0');

echo "rater stats OK\n";
