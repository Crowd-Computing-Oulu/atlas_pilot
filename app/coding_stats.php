<?php
/**
 * Pure helpers for rater quality-assurance stats (no DB, no output) so they can be
 * unit-tested. Used by the admin Coding tab raters-overview and per-rater drill-in.
 */

// Ratings faster than this many seconds are treated as suspiciously quick.
const RATER_FAST_SECONDS = 20;

// Median of a list of numbers, ignoring nulls. Returns null if nothing is left.
function coding_median(array $nums): ?float {
    $nums = array_values(array_filter($nums, fn($n) => $n !== null));
    sort($nums);
    $n = count($nums);
    if ($n === 0) return null;
    $mid = intdiv($n, 2);
    return ($n % 2) ? (float)$nums[$mid] : ($nums[$mid - 1] + $nums[$mid]) / 2.0;
}

/**
 * Aggregate human rating rows into per-rater QA stats, most-suspicious first.
 * Each input row needs: rater_pid, coding_seconds (int|null), technique_count (int|null).
 * Returns one entry per rater with:
 *   pid, n, median, min, n_timed, n_fast, pct_fast, tc (counts keyed '1','2','3').
 * Sort: highest share of fast ratings first, then lowest median (untimed raters last).
 */
function rater_overview(array $rows, int $fastSeconds): array {
    $by = [];
    foreach ($rows as $r) {
        $pid = $r['rater_pid'] ?? '';
        if ($pid === '') continue;
        if (!isset($by[$pid])) $by[$pid] = ['secs' => [], 'tc' => ['1' => 0, '2' => 0, '3' => 0], 'n' => 0];
        $by[$pid]['n']++;
        $s = $r['coding_seconds'] ?? null;
        if ($s !== null && $s !== '') $by[$pid]['secs'][] = (int)$s;
        $tc = $r['technique_count'] ?? null;
        if ($tc !== null && $tc !== '' && (int)$tc >= 1 && (int)$tc <= 3) {
            $by[$pid]['tc'][(string)(int)$tc]++;
        }
    }
    $out = [];
    foreach ($by as $pid => $a) {
        $timed = $a['secs'];
        $n_timed = count($timed);
        $n_fast = count(array_filter($timed, fn($s) => $s < $fastSeconds));
        $out[] = [
            'pid' => $pid,
            'n' => $a['n'],
            'median' => coding_median($timed),
            'min' => $n_timed ? min($timed) : null,
            'n_timed' => $n_timed,
            'n_fast' => $n_fast,
            'pct_fast' => $n_timed ? (float)$n_fast / $n_timed : 0.0,
            'tc' => $a['tc'],
        ];
    }
    usort($out, function ($x, $y) {
        if ($x['pct_fast'] !== $y['pct_fast']) return $y['pct_fast'] <=> $x['pct_fast'];
        $xm = $x['median'] ?? PHP_INT_MAX;
        $ym = $y['median'] ?? PHP_INT_MAX;
        return $xm <=> $ym;
    });
    return $out;
}
