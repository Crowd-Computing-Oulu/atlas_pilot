<?php
require_once __DIR__ . '/db.php';

$config = require __DIR__ . '/config.php';
$key = $_GET['key'] ?? '';
if ($key !== $config['admin_key']) {
    http_response_code(403);
    die('Access denied.');
}

$db = get_db();
$view = $_GET['view'] ?? 'overview';
$base_url = "admin.php?key=" . urlencode($key);

// Handle exports
if ($view === 'export') {
    $table = $_GET['table'] ?? '';
    $allowed = ['participants', 'responses', 'practice_extractions', 'questionnaire', 'codings', 'coding_tasks'];
    if (!in_array($table, $allowed)) die('Invalid table');

    // PHP's SQLite3Result does not have fetchAll(); build rows via fetchArray loop.
    if ($table === 'participants') {
        $sql = "SELECT * FROM participants ORDER BY id";
    } elseif ($table === 'responses') {
        $sql = "SELECT r.*, p.prolific_pid, p.condition_num FROM responses r JOIN participants p ON r.participant_id = p.id ORDER BY r.id";
    } elseif ($table === 'practice_extractions') {
        $sql = "SELECT g.*, p.prolific_pid, p.condition_num FROM practice_extractions g JOIN participants p ON g.participant_id = p.id ORDER BY g.id";
    } elseif ($table === 'codings') {
        // One row per rating (human or model), tied back to participant/condition/practice.
        $sql = "SELECT c.id AS coding_id, c.task_id, t.token, t.participant_id, p.prolific_pid, t.condition_num, t.text_role,
                       c.source, c.rater_pid, c.technique, c.dosage, c.mode, c.technique_count, c.notes, c.created_at
                FROM codings c
                JOIN coding_tasks t ON t.id = c.task_id
                JOIN participants p ON p.id = t.participant_id
                ORDER BY t.participant_id, t.text_role, c.source, c.id";
    } elseif ($table === 'coding_tasks') {
        $sql = "SELECT t.id AS task_id, t.token, t.participant_id, p.prolific_pid, t.condition_num, t.text_role,
                       t.target_raters, t.text_content, t.created_at
                FROM coding_tasks t JOIN participants p ON p.id = t.participant_id ORDER BY t.id";
    } else {
        $sql = "SELECT q.*, p.prolific_pid, p.condition_num FROM questionnaire q JOIN participants p ON q.participant_id = p.id ORDER BY q.id";
    }

    $result = $db->query($sql);
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }

    header('Content-Type: text/csv');
    header("Content-Disposition: attachment; filename={$table}_" . date('Y-m-d') . ".csv");
    if (!empty($rows)) {
        $out = fopen('php://output', 'w');
        // Explicit escape '' = standard CSV and future-proofs the PHP 8.4 fputcsv deprecation.
        fputcsv($out, array_keys($rows[0]), ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($out, $row, ',', '"', '');
        }
        fclose($out);
    }
    exit;
}

// Handle participant deletion
if ($view === 'delete' && isset($_GET['id'])) {
    $del_id = (int)$_GET['id'];
    $db->exec("DELETE FROM questionnaire WHERE participant_id = {$del_id}");
    $db->exec("DELETE FROM practice_extractions WHERE participant_id = {$del_id}");
    $db->exec("DELETE FROM responses WHERE participant_id = {$del_id}");
    $db->exec("DELETE FROM participants WHERE id = {$del_id}");
    $return_view = ($_GET['return'] ?? '') === 'participants' ? 'participants' : 'overview';
    header("Location: {$base_url}&view={$return_view}");
    exit;
}

// Handle DB download
if ($view === 'download_db') {
    $db_path = $config['db_path'];
    if (file_exists($db_path)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename=atlas_' . date('Y-m-d_His') . '.db');
        header('Content-Length: ' . filesize($db_path));
        readfile($db_path);
    }
    exit;
}

// === Coding facility: which participants/texts get coded ===
const CODING_PILOT_IDS = [6, 7, 8, 9, 10, 11]; // soft-launch pilot rows, excluded from the analysis set

function coding_initial_text(SQLite3 $db, int $pid): ?string {
    $s = $db->prepare("SELECT response_text FROM responses WHERE participant_id=:p AND step='initial_description' ORDER BY id LIMIT 1");
    $s->bindValue(':p', $pid, SQLITE3_INTEGER);
    $r = $s->execute()->fetchArray(SQLITE3_ASSOC);
    return $r ? $r['response_text'] : null;
}

function coding_c3_text(SQLite3 $db, int $pid, string $which): ?string {
    // round 0 snapshot is the initial text; max round is the final edited text.
    $order = $which === 'first' ? 'ASC' : 'DESC';
    $s = $db->prepare("SELECT description_snapshot FROM practice_extractions
                       WHERE participant_id=:p AND description_snapshot IS NOT NULL AND TRIM(description_snapshot)!=''
                       ORDER BY round {$order} LIMIT 1");
    $s->bindValue(':p', $pid, SQLITE3_INTEGER);
    $r = $s->execute()->fetchArray(SQLITE3_ASSOC);
    if ($r && trim((string)$r['description_snapshot']) !== '') return $r['description_snapshot'];
    return coding_initial_text($db, $pid); // fallback if snapshots were not captured
}

function coding_models(array $config): array {
    $m = $config['coding_models'] ?? null;
    if (is_array($m) && $m) return $m;
    return [$config['llm_model']]; // default: just the live model; add OpenRouter slugs in config['coding_models']
}

// Seed coding tasks from the analysis set (completed Prolific participants, pilot excluded).
if ($view === 'coding_seed') {
    $excl = implode(',', CODING_PILOT_IDS);
    $valid = [];
    $res = $db->query("SELECT id, condition_num FROM participants
                       WHERE source='prolific' AND completed_at IS NOT NULL AND id NOT IN ({$excl}) ORDER BY id");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) { $valid[] = $row; }

    $created = 0;
    foreach ($valid as $p) {
        $pid = (int)$p['id'];
        $cond = (int)$p['condition_num'];
        $tasks = $cond === 3
            ? [['c3_first', coding_c3_text($db, $pid, 'first')], ['c3_final', coding_c3_text($db, $pid, 'final')]]
            : [['single', coding_initial_text($db, $pid)]];
        foreach ($tasks as [$role, $text]) {
            if ($text === null || trim($text) === '') continue;
            $ins = $db->prepare("INSERT OR IGNORE INTO coding_tasks (token, participant_id, condition_num, text_role, text_content, target_raters)
                                 VALUES (:tok, :pid, :c, :role, :txt, 3)");
            $ins->bindValue(':tok', bin2hex(random_bytes(8)), SQLITE3_TEXT);
            $ins->bindValue(':pid', $pid, SQLITE3_INTEGER);
            $ins->bindValue(':c', $cond, SQLITE3_INTEGER);
            $ins->bindValue(':role', $role, SQLITE3_TEXT);
            $ins->bindValue(':txt', $text, SQLITE3_TEXT);
            $ins->execute();
            if ($db->changes() > 0) $created++;
        }
    }
    header("Location: {$base_url}&view=coding&seeded={$created}");
    exit;
}

// Export task URLs for Prolific Taskflow. One row per task (no repeated URLs).
//   column A = url, column B = participants/allocation per task (Taskflow's per-task rater count).
// Params: &alloc=N sets column B (default 3); &sample=N exports a stratified pilot subset
// (balanced across condition x role); &plain=1 emits url-only if your Taskflow flow wants a single column.
if ($view === 'coding_csv') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base = ($config['app_base_url'] ?? '') ?: ($scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $base = rtrim($base, '/');
    $alloc = max(1, min(50, (int)($_GET['alloc'] ?? 3)));
    $sample = isset($_GET['sample']) ? max(1, (int)$_GET['sample']) : 0;
    $plain = ($_GET['plain'] ?? '') === '1';

    $tokens = [];
    if ($sample > 0) {
        // Stratified by condition x role so the rubric pilot stress-tests every text type.
        $strata = fetch_all($db, "SELECT condition_num, text_role FROM coding_tasks GROUP BY condition_num, text_role ORDER BY condition_num, text_role");
        $per = (int)ceil($sample / max(1, count($strata)));
        foreach ($strata as $s) {
            $st = $db->prepare("SELECT token FROM coding_tasks WHERE condition_num=:c AND text_role=:r ORDER BY id LIMIT :l");
            $st->bindValue(':c', $s['condition_num'], SQLITE3_INTEGER);
            $st->bindValue(':r', $s['text_role'], SQLITE3_TEXT);
            $st->bindValue(':l', $per, SQLITE3_INTEGER);
            $res = $st->execute();
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) { $tokens[] = $row['token']; }
        }
    } else {
        $res = $db->query("SELECT token FROM coding_tasks ORDER BY id");
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) { $tokens[] = $row['token']; }
    }

    header('Content-Type: text/csv');
    $fn = ($sample > 0 ? "taskflow_pilot_" . count($tokens) : "taskflow_full_" . count($tokens)) . "_x{$alloc}";
    header("Content-Disposition: attachment; filename={$fn}_" . date('Y-m-d') . ".csv");
    $out = fopen('php://output', 'w');
    // Explicit escape '' = standard CSV and future-proofs the PHP 8.4 fputcsv deprecation.
    fputcsv($out, $plain ? ['url'] : ['url', 'participants'], ',', '"', '');
    foreach ($tokens as $t) {
        $url = $base . '/code.php?task=' . $t;
        fputcsv($out, $plain ? [$url] : [$url, $alloc], ',', '"', '');
    }
    fclose($out);
    exit;
}

// Batch LLM coding: code up to N uncoded (task, model) pairs, then return to the dashboard.
if ($view === 'coding_llm') {
    @set_time_limit(0);
    require_once __DIR__ . '/llm.php';
    $models = coding_models($config);
    $budget = max(1, min(60, (int)($_GET['n'] ?? 10)));
    $coded = 0; $failed = 0;
    foreach ($models as $model) {
        if ($budget <= 0) break;
        $sel = $db->prepare("SELECT t.id, t.text_content FROM coding_tasks t
                             WHERE NOT EXISTS (SELECT 1 FROM codings c WHERE c.task_id = t.id AND c.source = :m)
                             ORDER BY t.id LIMIT :lim");
        $sel->bindValue(':m', $model, SQLITE3_TEXT);
        $sel->bindValue(':lim', $budget, SQLITE3_INTEGER);
        $pending = [];
        $r = $sel->execute();
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) { $pending[] = $row; }
        foreach ($pending as $row) {
            if ($budget <= 0) break;
            $budget--;
            $parsed = analyse_practice($row['text_content'], $model);
            if (!$parsed) { $failed++; continue; }
            $ins = $db->prepare("INSERT INTO codings (task_id, source, technique, dosage, mode, technique_count, raw_llm_response)
                                 VALUES (:tid, :m, :t, :d, :mo, :tc, :raw)");
            $ins->bindValue(':tid', $row['id'], SQLITE3_INTEGER);
            $ins->bindValue(':m', $model, SQLITE3_TEXT);
            $ins->bindValue(':t', (int)$parsed['technique']['level'], SQLITE3_INTEGER);
            $ins->bindValue(':d', (int)$parsed['dosage']['level'], SQLITE3_INTEGER);
            $ins->bindValue(':mo', (int)$parsed['mode']['level'], SQLITE3_INTEGER);
            $ins->bindValue(':tc', (int)($parsed['technique_count'] ?? 1), SQLITE3_INTEGER);
            $ins->bindValue(':raw', $parsed['_raw'] ?? null, SQLITE3_TEXT);
            $ins->execute();
            $coded++;
        }
    }
    header("Location: {$base_url}&view=coding&llm_coded={$coded}&llm_failed={$failed}");
    exit;
}

// Delete a single coding (rating).
if ($view === 'coding_delete' && isset($_GET['cid'])) {
    $cid = (int)$_GET['cid'];
    $db->exec("DELETE FROM codings WHERE id = {$cid}");
    header("Location: {$base_url}&view=coding");
    exit;
}

// Clear all codings from one source (a model run, or 'human').
if ($view === 'coding_clear_source' && isset($_GET['src'])) {
    $stmt = $db->prepare("DELETE FROM codings WHERE source = :s");
    $stmt->bindValue(':s', $_GET['src'], SQLITE3_TEXT);
    $stmt->execute();
    header("Location: {$base_url}&view=coding");
    exit;
}

// Helper to run a query and fetch all rows
function fetch_all(SQLite3 $db, string $sql): array {
    $result = $db->query($sql);
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }
    return $rows;
}

// === Descriptive-stats helpers ===
function arr_clean(array $a): array {
    return array_values(array_filter($a, fn($v) => $v !== null && $v !== '' && is_numeric($v)));
}
function arr_mean(array $a) {
    $a = arr_clean($a);
    return $a ? array_sum($a) / count($a) : null;
}
function arr_sd(array $a) {
    $a = arr_clean($a);
    if (count($a) < 2) return null;
    $m = array_sum($a) / count($a);
    $sq = 0;
    foreach ($a as $v) { $sq += ($v - $m) ** 2; }
    return sqrt($sq / (count($a) - 1));
}
function arr_median(array $a) {
    $a = arr_clean($a);
    if (!$a) return null;
    sort($a);
    $n = count($a);
    return $n % 2 ? $a[(int)($n / 2)] : ($a[(int)($n / 2) - 1] + $a[(int)($n / 2)]) / 2;
}
function fmt_ms($mean, $sd, $d = 2): string {
    if ($mean === null) return '—';
    return number_format($mean, $d) . ($sd !== null ? ' (' . number_format($sd, $d) . ')' : '');
}
function fmt_num($v, $d = 2): string {
    return $v === null ? '—' : number_format($v, $d);
}
function fmt_pct($num, $denom): string {
    return $denom ? round(($num / $denom) * 100) . '%' : '—';
}
function pluck(array $rows, string $field): array {
    $out = [];
    foreach ($rows as $r) { $out[] = $r[$field] ?? null; }
    return $out;
}
function pss_zerovar(array $r): ?int {
    $q = [$r['pss4_q1'], $r['pss4_q2'], $r['pss4_q3'], $r['pss4_q4']];
    if (in_array(null, $q, true)) return null;
    return count(array_unique($q)) === 1 ? 1 : 0;
}
function gad_zerovar(array $r): ?int {
    $q = [$r['gad2_q1'], $r['gad2_q2']];
    if (in_array(null, $q, true)) return null;
    return count(array_unique($q)) === 1 ? 1 : 0;
}

$page_title = 'ATLAS Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; }
        .stat-card { background: white; border-radius: 8px; padding: 1.25rem; box-shadow: 0 1px 4px rgba(0,0,0,0.06); text-align: center; }
        .stat-card .number { font-size: 2rem; font-weight: 700; }
        .stat-card .label { font-size: 0.85rem; color: #6c757d; }
        .bar { height: 20px; border-radius: 3px; display: inline-block; }
        .table td, .table th { vertical-align: middle; }
        pre.raw { max-height: 200px; overflow: auto; font-size: 0.75rem; background: #f1f3f5; padding: 0.5rem; border-radius: 4px; }
    </style>
</head>
<body>
<div class="container-fluid py-4" style="max-width: 1200px;">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3>ATLAS Admin Dashboard</h3>
        <div>
            <a href="<?= $base_url ?>&view=overview" class="btn btn-sm <?= $view === 'overview' ? 'btn-primary' : 'btn-outline-primary' ?>">Overview</a>
            <a href="<?= $base_url ?>&view=participants" class="btn btn-sm <?= $view === 'participants' ? 'btn-primary' : 'btn-outline-primary' ?>">Participants</a>
            <a href="<?= $base_url ?>&view=coding" class="btn btn-sm <?= $view === 'coding' ? 'btn-primary' : 'btn-outline-primary' ?>">Coding</a>
            <div class="btn-group">
                <button type="button" class="btn btn-sm btn-outline-success dropdown-toggle" data-bs-toggle="dropdown">Export</button>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="<?= $base_url ?>&view=export&table=participants">Participants CSV</a></li>
                    <li><a class="dropdown-item" href="<?= $base_url ?>&view=export&table=responses">Responses CSV</a></li>
                    <li><a class="dropdown-item" href="<?= $base_url ?>&view=export&table=practice_extractions">Practice Extractions CSV</a></li>
                    <li><a class="dropdown-item" href="<?= $base_url ?>&view=export&table=questionnaire">Questionnaire CSV</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="<?= $base_url ?>&view=export&table=codings"><strong>Ratings (codings) CSV</strong></a></li>
                    <li><a class="dropdown-item" href="<?= $base_url ?>&view=export&table=coding_tasks">Coding tasks CSV</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="<?= $base_url ?>&view=download_db"><strong>Download SQLite DB</strong></a></li>
                </ul>
            </div>
        </div>
    </div>

<?php if ($view === 'overview'): ?>

    <?php
    $total = $db->querySingle("SELECT COUNT(*) FROM participants");
    $completed = $db->querySingle("SELECT COUNT(*) FROM participants WHERE completed_at IS NOT NULL");
    $cond_counts = fetch_all($db, "SELECT condition_num, COUNT(*) as n, SUM(CASE WHEN completed_at IS NOT NULL THEN 1 ELSE 0 END) as completed FROM participants GROUP BY condition_num ORDER BY condition_num");
    $avg_times = fetch_all($db, "SELECT condition_num, ROUND(AVG((julianday(completed_at) - julianday(started_at)) * 1440), 1) as avg_min FROM participants WHERE completed_at IS NOT NULL GROUP BY condition_num");
    $avg_map = [];
    foreach ($avg_times as $at) $avg_map[$at['condition_num']] = $at['avg_min'];
    ?>

    <div class="card mb-4">
        <div class="card-body">
            <h5>Test Links <small class="text-muted">(no data stored)</small></h5>
            <div class="d-flex gap-2">
                <a href="index.php?test=1&condition=1" target="_blank" class="btn btn-outline-primary btn-sm">Condition 1 — Baseline</a>
                <a href="index.php?test=1&condition=2" target="_blank" class="btn btn-outline-primary btn-sm">Condition 2 — Nudge</a>
                <a href="index.php?test=1&condition=3" target="_blank" class="btn btn-outline-primary btn-sm">Condition 3 — AI Coach</a>
            </div>
            <h6 class="mt-3 mb-2">Preview <small class="text-muted">(auto-filled, no data stored)</small></h6>
            <div class="d-flex gap-2">
                <a href="index.php?test=1&fill=1&condition=1" target="_blank" class="btn btn-outline-secondary btn-sm">C1 Baseline</a>
                <a href="index.php?test=1&fill=1&condition=2" target="_blank" class="btn btn-outline-secondary btn-sm">C2 Nudge</a>
                <a href="index.php?test=1&fill=1&condition=3" target="_blank" class="btn btn-outline-secondary btn-sm">C3 AI Coach</a>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="stat-card">
                <div class="number"><?= $total ?></div>
                <div class="label">Total Participants</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="number"><?= $completed ?></div>
                <div class="label">Completed</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="number"><?= $total > 0 ? round($completed / $total * 100) : 0 ?>%</div>
                <div class="label">Completion Rate</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="number"><?= count($cond_counts) ?></div>
                <div class="label">Active Conditions</div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <h5>Per Condition</h5>
            <table class="table table-sm mb-0">
                <thead><tr><th>Condition</th><th>N</th><th>Completed</th><th>Avg. Time (min)</th><th>Distribution</th></tr></thead>
                <tbody>
                <?php
                $cond_labels = [1 => 'Baseline', 2 => 'Nudge', 3 => 'AI Coach'];
                foreach ($cond_counts as $cc):
                    $pct = $total > 0 ? ($cc['n'] / $total * 100) : 0;
                ?>
                <tr>
                    <td><strong><?= $cond_labels[$cc['condition_num']] ?? $cc['condition_num'] ?></strong></td>
                    <td><?= $cc['n'] ?></td>
                    <td><?= $cc['completed'] ?></td>
                    <td><?= $avg_map[$cc['condition_num']] ?? '—' ?></td>
                    <td><div class="bar bg-primary" style="width: <?= $pct ?>%">&nbsp;</div> <?= round($pct) ?>%</td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php
    // === Per-condition descriptive panel ===
    $desc_rows = fetch_all($db, "
        SELECT
            p.id, p.condition_num, p.source,
            CASE WHEN p.completed_at IS NOT NULL THEN 1 ELSE 0 END AS completed,
            p.pss4_sum, p.pss4_q1, p.pss4_q2, p.pss4_q3, p.pss4_q4,
            p.gad2_sum, p.gad2_q1, p.gad2_q2,
            p.rounds_taken,
            CASE WHEN p.completed_at IS NOT NULL
                 THEN (julianday(p.completed_at) - julianday(p.started_at)) * 1440
                 ELSE NULL END AS minutes_total,
            q.semantic_fidelity, q.self_distortion, q.willingness, q.attention_check,
            (SELECT LENGTH(response_text) FROM responses WHERE participant_id = p.id AND step = 'initial_description' ORDER BY id LIMIT 1) AS init_chars,
            (SELECT response_text FROM responses WHERE participant_id = p.id AND step = 'initial_description' ORDER BY id LIMIT 1) AS init_text
        FROM participants p
        LEFT JOIN questionnaire q ON q.participant_id = p.id
    ");

    foreach ($desc_rows as &$r) {
        $r['init_words'] = $r['init_text'] !== null ? str_word_count((string)$r['init_text']) : null;
        $r['pss_zerovar'] = pss_zerovar($r);
        $r['gad_zerovar'] = gad_zerovar($r);
        $r['imc_pass'] = $r['attention_check'] !== null ? ((int)$r['attention_check'] === 1 ? 1 : 0) : null;
    }
    unset($r);

    $desc_ext = fetch_all($db, "SELECT participant_id, round, technique_level, dosage_level, mode_level FROM practice_extractions ORDER BY participant_id, round");
    $ext_first = []; $ext_last = [];
    foreach ($desc_ext as $e) {
        $pid = (int)$e['participant_id'];
        if (!isset($ext_first[$pid])) $ext_first[$pid] = $e;
        $ext_last[$pid] = $e;
    }

    // Partition by condition + overall
    $by_cond = [1 => [], 2 => [], 3 => [], 'all' => []];
    foreach ($desc_rows as $r) {
        $c = (int)$r['condition_num'];
        if (isset($by_cond[$c])) {
            $by_cond[$c][] = $r;
            $by_cond['all'][] = $r;
        }
    }

    // Build C3-only level/delta lists per condition cell (C1/C2 will be all-null).
    $level_vals = [];
    foreach ([1, 2, 3, 'all'] as $cc) {
        foreach (['technique', 'dosage', 'mode'] as $dim) {
            $level_vals[$cc]['first_' . $dim] = [];
            $level_vals[$cc]['last_'  . $dim] = [];
            $level_vals[$cc]['delta_' . $dim] = [];
        }
        foreach ($by_cond[$cc] as $r) {
            $pid = (int)$r['id'];
            if (!isset($ext_first[$pid])) continue;
            foreach (['technique', 'dosage', 'mode'] as $dim) {
                $f = $ext_first[$pid][$dim . '_level'] ?? null;
                $l = $ext_last [$pid][$dim . '_level'] ?? null;
                if ($f !== null) $level_vals[$cc]['first_' . $dim][] = (float)$f;
                if ($l !== null) $level_vals[$cc]['last_'  . $dim][] = (float)$l;
                if ($f !== null && $l !== null) $level_vals[$cc]['delta_' . $dim][] = (float)$l - (float)$f;
            }
        }
    }

    // Render helpers (closures over $by_cond / $level_vals).
    $cells_ms = function (string $field, int $d = 2) use ($by_cond) {
        $out = '';
        foreach ([1, 2, 3, 'all'] as $c) {
            $vals = pluck($by_cond[$c], $field);
            $out .= '<td>' . fmt_ms(arr_mean($vals), arr_sd($vals), $d) . '</td>';
        }
        return $out;
    };
    $cells_median = function (string $field, int $d = 1) use ($by_cond) {
        $out = '';
        foreach ([1, 2, 3, 'all'] as $c) {
            $vals = pluck($by_cond[$c], $field);
            $out .= '<td>' . fmt_num(arr_median($vals), $d) . '</td>';
        }
        return $out;
    };
    $cells_count = function (callable $pred) use ($by_cond) {
        $out = '';
        foreach ([1, 2, 3, 'all'] as $c) {
            $n = 0;
            foreach ($by_cond[$c] as $r) if ($pred($r)) $n++;
            $out .= "<td>{$n}</td>";
        }
        return $out;
    };
    $cells_count_rate = function (string $count_field, string $denom_field) use ($by_cond) {
        $out = '';
        foreach ([1, 2, 3, 'all'] as $c) {
            $num = 0; $den = 0;
            foreach ($by_cond[$c] as $r) {
                if ($r[$denom_field] !== null) {
                    $den++;
                    if ((int)$r[$count_field] === 1) $num++;
                }
            }
            $out .= '<td>' . $num . ' / ' . $den . ' (' . fmt_pct($num, $den) . ')</td>';
        }
        return $out;
    };
    $cells_levels_ms = function (string $key, int $d = 2) use ($level_vals) {
        $out = '';
        foreach ([1, 2, 3, 'all'] as $c) {
            $vals = $level_vals[$c][$key];
            $out .= '<td>' . fmt_ms(arr_mean($vals), arr_sd($vals), $d) . '</td>';
        }
        return $out;
    };
    $cells_n = function () use ($by_cond) {
        $out = '';
        foreach ([1, 2, 3, 'all'] as $c) $out .= '<td><strong>' . count($by_cond[$c]) . '</strong></td>';
        return $out;
    };
    $cells_completed = function () use ($by_cond) {
        $out = '';
        foreach ([1, 2, 3, 'all'] as $c) {
            $n = count($by_cond[$c]);
            $done = 0; foreach ($by_cond[$c] as $r) if ((int)$r['completed'] === 1) $done++;
            $out .= "<td>{$done} (" . fmt_pct($done, $n) . ')</td>';
        }
        return $out;
    };
    $cells_source = function (string $src) use ($by_cond) {
        $out = '';
        foreach ([1, 2, 3, 'all'] as $c) {
            $n = 0; foreach ($by_cond[$c] as $r) if ($r['source'] === $src) $n++;
            $out .= "<td>{$n}</td>";
        }
        return $out;
    };
    $cells_rt_bucket = function ($predicate) use ($by_cond) {
        $out = '';
        foreach ([1, 2, 3, 'all'] as $c) {
            $den = 0; $num = 0;
            foreach ($by_cond[$c] as $r) {
                if ($r['rounds_taken'] !== null) {
                    $den++;
                    if ($predicate((int)$r['rounds_taken'])) $num++;
                }
            }
            $out .= '<td>' . ($den ? $num . ' (' . fmt_pct($num, $den) . ')' : '—') . '</td>';
        }
        return $out;
    };

    $section = function (string $label) {
        return "<tr class='table-secondary'><td colspan='5' class='fw-bold'>{$label}</td></tr>";
    };
    ?>

    <div class="card mb-4">
        <div class="card-body">
            <h5 class="mb-3">Descriptive Statistics by Condition</h5>
            <p class="text-muted small mb-3">Means with (SD) in parentheses unless noted. "Overall" pools all conditions. C3 telemetry rows are blank for C1 / C2 by design.</p>
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th style="width:32%">Metric</th><th>C1 Baseline</th><th>C2 Nudge</th><th>C3 AI Coach</th><th>Overall</th></tr></thead>
                <tbody>

                <?= $section('Sample &amp; drop-off') ?>
                <tr><td>N assigned</td><?= $cells_n() ?></tr>
                <tr><td>N completed (% of assigned)</td><?= $cells_completed() ?></tr>
                <tr><td>Source: Prolific</td><?= $cells_source('prolific') ?></tr>
                <tr><td>Source: Web</td><?= $cells_source('web') ?></tr>

                <?= $section('Time on task (completed only)') ?>
                <tr><td>Minutes total, mean (SD)</td><?= $cells_ms('minutes_total', 2) ?></tr>
                <tr><td>Minutes total, median</td><?= $cells_median('minutes_total', 1) ?></tr>

                <?= $section('Intake screeners (sample characterisation)') ?>
                <tr><td>PSS-4 sum (0–16), mean (SD)</td><?= $cells_ms('pss4_sum', 2) ?></tr>
                <tr><td>PSS-4 sum, median</td><?= $cells_median('pss4_sum', 1) ?></tr>
                <tr><td>PSS-4 zero-variance count</td><?= $cells_count(fn($r) => $r['pss_zerovar'] === 1) ?></tr>
                <tr><td>GAD-2 sum (0–6), mean (SD)</td><?= $cells_ms('gad2_sum', 2) ?></tr>
                <tr><td>GAD-2 sum, median</td><?= $cells_median('gad2_sum', 1) ?></tr>
                <tr><td>GAD-2 zero-variance count</td><?= $cells_count(fn($r) => $r['gad_zerovar'] === 1) ?></tr>

                <?= $section('Initial practice description (free text)') ?>
                <tr><td>Char count, mean (SD)</td><?= $cells_ms('init_chars', 1) ?></tr>
                <tr><td>Char count, median</td><?= $cells_median('init_chars', 0) ?></tr>
                <tr><td>Word count, mean (SD)</td><?= $cells_ms('init_words', 1) ?></tr>
                <tr><td>Word count, median</td><?= $cells_median('init_words', 0) ?></tr>

                <?= $section('C3 AI-coach telemetry (refinement trajectory)') ?>
                <tr><td>RoundsTaken, mean (SD)</td><?= $cells_ms('rounds_taken', 2) ?></tr>
                <tr><td>RoundsTaken, median</td><?= $cells_median('rounds_taken', 1) ?></tr>
                <tr><td>RoundsTaken = 0 (accepted at first check)</td><?= $cells_rt_bucket(fn($v) => $v === 0) ?></tr>
                <tr><td>RoundsTaken = 1</td><?= $cells_rt_bucket(fn($v) => $v === 1) ?></tr>
                <tr><td>RoundsTaken = 2</td><?= $cells_rt_bucket(fn($v) => $v === 2) ?></tr>
                <tr><td>RoundsTaken ≥ 3</td><?= $cells_rt_bucket(fn($v) => $v >= 3) ?></tr>

                <?= $section('C3 LLM specificity 0–3 (telemetry, not the DV)') ?>
                <tr><td>Round 0: Technique level, mean (SD)</td><?= $cells_levels_ms('first_technique', 2) ?></tr>
                <tr><td>Round 0: Dosage level, mean (SD)</td><?= $cells_levels_ms('first_dosage', 2) ?></tr>
                <tr><td>Round 0: Mode level, mean (SD)</td><?= $cells_levels_ms('first_mode', 2) ?></tr>
                <tr><td>Final round: Technique level, mean (SD)</td><?= $cells_levels_ms('last_technique', 2) ?></tr>
                <tr><td>Final round: Dosage level, mean (SD)</td><?= $cells_levels_ms('last_dosage', 2) ?></tr>
                <tr><td>Final round: Mode level, mean (SD)</td><?= $cells_levels_ms('last_mode', 2) ?></tr>
                <tr><td>Δ Technique (final − round 0), mean (SD)</td><?= $cells_levels_ms('delta_technique', 2) ?></tr>
                <tr><td>Δ Dosage (final − round 0), mean (SD)</td><?= $cells_levels_ms('delta_dosage', 2) ?></tr>
                <tr><td>Δ Mode (final − round 0), mean (SD)</td><?= $cells_levels_ms('delta_mode', 2) ?></tr>

                <?= $section('Fidelity / outcome questionnaire (Likert 1–7)') ?>
                <tr><td>Semantic Fidelity, mean (SD)</td><?= $cells_ms('semantic_fidelity', 2) ?></tr>
                <tr><td>Self-Distortion, mean (SD)</td><?= $cells_ms('self_distortion', 2) ?></tr>
                <tr><td>Willingness (unpaid contribution), mean (SD)</td><?= $cells_ms('willingness', 2) ?></tr>

                <?= $section('Attention check (IMC)') ?>
                <tr><td>PASS / responded (rate)</td><?= $cells_count_rate('imc_pass', 'attention_check') ?></tr>
                <tr><td>Raw value, mean (SD)</td><?= $cells_ms('attention_check', 2) ?></tr>

                </tbody>
            </table>
        </div>
    </div>

    <?php
    // Recent participants
    $recent = fetch_all($db, "SELECT * FROM participants ORDER BY id DESC LIMIT 10");
    ?>
    <div class="card">
        <div class="card-body">
            <h5>Recent Participants</h5>
            <table class="table table-sm table-hover mb-0">
                <thead><tr><th>ID</th><th>PID</th><th>Source</th><th>Condition</th><th>Started</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($recent as $p): ?>
                <tr>
                    <td style="cursor:pointer" onclick="window.location='<?= $base_url ?>&view=detail&id=<?= $p['id'] ?>'"><?= $p['id'] ?></td>
                    <td style="cursor:pointer" onclick="window.location='<?= $base_url ?>&view=detail&id=<?= $p['id'] ?>'"><code><?= htmlspecialchars($p['prolific_pid']) ?></code></td>
                    <td><?= $p['source'] ?></td>
                    <td><?= $cond_labels[$p['condition_num']] ?? $p['condition_num'] ?></td>
                    <td><?= $p['started_at'] ?></td>
                    <td><?= $p['completed_at'] ? '<span class="badge bg-success">Done</span>' : '<span class="badge bg-warning">In progress</span>' ?></td>
                    <td><a href="<?= $base_url ?>&view=delete&id=<?= $p['id'] ?>" onclick="return confirm('Delete all data for this participant?')" title="Delete participant and all data" class="text-danger text-decoration-none">&#128465;</a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php elseif ($view === 'participants'): ?>

    <?php $participants = fetch_all($db, "SELECT * FROM participants ORDER BY id DESC"); ?>
    <div class="card">
        <div class="card-body">
            <h5>All Participants (<?= count($participants) ?>)</h5>
            <table class="table table-sm table-hover">
                <thead><tr><th>ID</th><th>PID</th><th>Source</th><th>Condition</th><th>Started</th><th>Completed</th><th>Code</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($participants as $p): ?>
                <tr style="cursor:pointer" onclick="window.location='<?= $base_url ?>&view=detail&id=<?= $p['id'] ?>'">
                    <td><?= $p['id'] ?></td>
                    <td><code><?= htmlspecialchars($p['prolific_pid']) ?></code></td>
                    <td><?= $p['source'] ?></td>
                    <td><?= $p['condition_num'] ?></td>
                    <td><?= $p['started_at'] ?></td>
                    <td><?= $p['completed_at'] ?: '—' ?></td>
                    <td><code><?= htmlspecialchars($p['completion_code']) ?></code></td>
                    <td onclick="event.stopPropagation()"><a href="<?= $base_url ?>&view=delete&id=<?= $p['id'] ?>" onclick="return confirm('Delete all data for participant #<?= $p['id'] ?>?')" title="Delete participant and all data" class="text-danger text-decoration-none">&#128465;</a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php elseif ($view === 'detail'): ?>

    <?php
    $pid = (int)($_GET['id'] ?? 0);
    $p = $db->querySingle("SELECT * FROM participants WHERE id = {$pid}", true);
    if (!$p) die('Participant not found');

    $responses = fetch_all($db, "SELECT * FROM responses WHERE participant_id = {$pid} ORDER BY id");
    $extractions = fetch_all($db, "SELECT * FROM practice_extractions WHERE participant_id = {$pid} ORDER BY round");
    $q = $db->querySingle("SELECT * FROM questionnaire WHERE participant_id = {$pid}", true);

    $cond_labels = [1 => 'Baseline', 2 => 'Nudge', 3 => 'AI Coach'];
    ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <a href="<?= $base_url ?>&view=participants" class="btn btn-sm btn-outline-secondary">&larr; Back</a>
        <a href="<?= $base_url ?>&view=delete&id=<?= $p['id'] ?>&return=participants" onclick="return confirm('Delete all data for participant #<?= $p['id'] ?>?')" class="btn btn-sm btn-outline-danger">&#128465; Delete participant</a>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h5>Participant #<?= $p['id'] ?> — <?= htmlspecialchars($p['prolific_pid']) ?></h5>
            <p>
                <strong>Condition:</strong> <?= $cond_labels[$p['condition_num']] ?? $p['condition_num'] ?> |
                <strong>Source:</strong> <?= $p['source'] ?> |
                <strong>Started:</strong> <?= $p['started_at'] ?> |
                <strong>Completed:</strong> <?= $p['completed_at'] ?: 'In progress' ?>
            </p>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h6>Responses</h6>
            <?php foreach ($responses as $r): ?>
            <div class="border rounded p-2 mb-2">
                <small class="text-muted"><?= $r['step'] ?> — <?= $r['created_at'] ?></small>
                <div class="mt-1"><em>Prompt:</em> <?= htmlspecialchars($r['prompt_shown']) ?></div>
                <div class="mt-1"><strong><?= htmlspecialchars($r['response_text']) ?></strong></div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($responses)): ?><p class="text-muted">No responses yet.</p><?php endif; ?>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h6>Practice Extractions (Refinement Trajectory)</h6>
            <table class="table table-sm">
                <thead><tr><th>Round</th><th>Technique (lvl)</th><th>Dosage (lvl)</th><th>Mode (lvl)</th></tr></thead>
                <tbody>
                <?php foreach ($extractions as $e): ?>
                <tr>
                    <td><?= $e['round'] ?></td>
                    <td><?= htmlspecialchars($e['technique'] ?? '—') ?> <small class="text-muted">(<?= $e['technique_level'] ?? '—' ?>)</small></td>
                    <td><?= htmlspecialchars($e['dosage'] ?? '—') ?> <small class="text-muted">(<?= $e['dosage_level'] ?? '—' ?>)</small></td>
                    <td><?= htmlspecialchars($e['mode'] ?? '—') ?> <small class="text-muted">(<?= $e['mode_level'] ?? '—' ?>)</small></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (!empty($extractions)): ?>
                <details>
                    <summary class="text-muted small">Raw LLM responses</summary>
                    <?php foreach ($extractions as $e): ?>
                        <p class="small mb-1"><strong>Round <?= $e['round'] ?>:</strong></p>
                        <pre class="raw"><?= htmlspecialchars($e['raw_llm_response'] ?? '') ?></pre>
                    <?php endforeach; ?>
                </details>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($q): ?>
    <div class="card mb-3">
        <div class="card-body">
            <h6>Questionnaire</h6>
            <table class="table table-sm">
                <tr><td>Semantic Fidelity</td><td><?= $q['semantic_fidelity'] ?>/7</td></tr>
                <tr><td>Self-reported Distortion</td><td><?= $q['self_distortion'] ?>/7</td></tr>
                <tr><td>Willingness to Contribute</td><td><?= $q['willingness'] ?>/7</td></tr>
                <?php
                    $ac = $q['attention_check'] ?? null;
                    $ac_label = $ac === null ? 'n/a' : (((int)$ac === 1) ? "PASS ({$ac}/7)" : "FAIL ({$ac}/7)");
                    $ac_class = $ac === null ? '' : (((int)$ac === 1) ? 'text-success fw-bold' : 'text-danger fw-bold');
                ?>
                <tr><td>Attention Check</td><td class="<?= $ac_class ?>"><?= $ac_label ?></td></tr>
                <tr><td>Context</td><td><?= htmlspecialchars($q['context_text'] ?: '—') ?></td></tr>
                <tr><td>Outcome</td><td><?= htmlspecialchars($q['outcome_text'] ?: '—') ?></td></tr>
                <tr><td>Fidelity Feedback</td><td><?= htmlspecialchars($q['fidelity_feedback'] ?: '—') ?></td></tr>
            </table>
        </div>
    </div>
    <?php endif; ?>

<?php elseif ($view === 'coding'): ?>
    <?php
    $ct_total = (int)$db->querySingle("SELECT COUNT(*) FROM coding_tasks");
    $roles = fetch_all($db, "SELECT condition_num, text_role, COUNT(*) n FROM coding_tasks GROUP BY condition_num, text_role ORDER BY condition_num, text_role");
    $cov = fetch_all($db, "SELECT (SELECT COUNT(*) FROM codings c WHERE c.task_id=t.id AND c.source='human') h FROM coding_tasks t");
    $h1 = 0; $h3 = 0;
    foreach ($cov as $c) { if ((int)$c['h'] >= 1) $h1++; if ((int)$c['h'] >= 3) $h3++; }
    $human_total = (int)$db->querySingle("SELECT COUNT(*) FROM codings WHERE source='human'");
    $models = coding_models($config);
    $model_counts = [];
    foreach (fetch_all($db, "SELECT source, COUNT(*) n FROM codings WHERE source!='human' GROUP BY source") as $m) {
        $model_counts[$m['source']] = (int)$m['n'];
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $app_base = rtrim(($config['app_base_url'] ?? '') ?: ($scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')), '/');
    $sample = $db->querySingle("SELECT token FROM coding_tasks ORDER BY id LIMIT 1");
    ?>

    <?php if (isset($_GET['seeded'])): ?><div class="alert alert-success">Seeded <?= (int)$_GET['seeded'] ?> new coding task(s).</div><?php endif; ?>
    <?php if (isset($_GET['llm_coded'])): ?><div class="alert alert-info">LLM coded <?= (int)$_GET['llm_coded'] ?> task(s); <?= (int)($_GET['llm_failed'] ?? 0) ?> failed.</div><?php endif; ?>

    <div class="card mb-3"><div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">Specificity Coding</h5>
            <div>
                <a href="code.php?preview=1<?= $sample ? '&task=' . htmlspecialchars(urlencode($sample)) : '' ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-info">Test coding &#8599;</a>
                <a href="<?= $base_url ?>&view=coding_seed" onclick="return confirm('Build coding tasks from the 300 completed Prolific participants? Existing tasks are kept (idempotent).')" class="btn btn-sm btn-primary">Seed tasks</a>
                <a href="<?= $base_url ?>&view=coding_csv&sample=48&alloc=5" class="btn btn-sm btn-outline-warning">Pilot CSV (~48 &times;5)</a>
                <a href="<?= $base_url ?>&view=coding_csv&alloc=3" class="btn btn-sm btn-outline-success">Full CSV (&times;3)</a>
            </div>
        </div>

        <table class="table table-sm w-auto">
            <tr><td>Total coding tasks</td><td><strong><?= $ct_total ?></strong></td></tr>
            <tr><td>Tasks with ≥1 human rating</td><td><?= $h1 ?> / <?= $ct_total ?> (<?= fmt_pct($h1, $ct_total) ?>)</td></tr>
            <tr><td>Tasks with ≥3 human ratings (target met)</td><td><?= $h3 ?> / <?= $ct_total ?> (<?= fmt_pct($h3, $ct_total) ?>)</td></tr>
            <tr><td>Total human ratings collected</td><td><?= $human_total ?></td></tr>
        </table>

        <?php if ($ct_total === 0): ?>
            <p class="text-muted">No tasks yet. Click <strong>Seed tasks</strong> to build them from the analysis set (C1/C2 → 1 text each; C3 → first + final snapshots).</p>
        <?php else: ?>
            <h6 class="mt-3">Tasks by condition and role</h6>
            <table class="table table-sm w-auto">
                <thead><tr><th>Condition</th><th>Role</th><th>Tasks</th></tr></thead>
                <?php foreach ($roles as $r): ?>
                    <tr><td>C<?= (int)$r['condition_num'] ?></td><td><?= htmlspecialchars($r['text_role']) ?></td><td><?= (int)$r['n'] ?></td></tr>
                <?php endforeach; ?>
            </table>
            <p class="small text-muted">Sample task URL for Taskflow: <code><?= htmlspecialchars($app_base . '/code.php?task=' . $sample) ?></code><br>
            CSV format: column A = url, column B = participants per task (allocation). <strong>Pilot CSV</strong> = a stratified ~48-task sample at &times;5 raters to calibrate the rubric first; <strong>Full CSV</strong> = all <?= $ct_total ?> at &times;3 (paper protocol: modal of 3). If your Taskflow flow wants a single column, append <code>&amp;plain=1</code> and set the count in the UI.</p>
        <?php endif; ?>
    </div></div>

    <div class="card mb-3"><div class="card-body">
        <h6>Multi-model LLM coding</h6>
        <p class="small text-muted mb-2">Codes each task against the same 0–3 rubric the humans use, once per configured model, for the LLM–human agreement analysis. Runs in restartable batches to avoid timeouts.</p>
        <table class="table table-sm w-auto">
            <thead><tr><th>Model</th><th>Tasks coded</th><th>Remaining</th></tr></thead>
            <?php foreach ($models as $m): $done = $model_counts[$m] ?? 0; ?>
                <tr>
                    <td><code><?= htmlspecialchars($m) ?></code></td>
                    <td><?= $done ?></td>
                    <td><?= max(0, $ct_total - $done) ?></td>
                    <td><?php if ($done > 0): ?><a href="<?= $base_url ?>&view=coding_clear_source&src=<?= htmlspecialchars(urlencode($m)) ?>" onclick="return confirm('Delete all <?= htmlspecialchars($done) ?> ratings from this model?')" class="text-danger small">clear</a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <?php if ($ct_total > 0): ?>
            <a href="<?= $base_url ?>&view=coding_llm&n=10" class="btn btn-sm btn-outline-primary">Code next 10</a>
            <a href="<?= $base_url ?>&view=coding_llm&n=40" class="btn btn-sm btn-outline-primary">Code next 40</a>
        <?php endif; ?>
        <p class="small text-muted mt-2 mb-0">Configure additional models with <code>'coding_models' =&gt; ['anthropic/claude-sonnet-4.6', '&lt;openrouter-slug&gt;', ...]</code> in <code>config.php</code>. Currently using <?= count($models) ?> model(s).</p>
    </div></div>

    <?php
    $all_codings = fetch_all($db, "SELECT c.id, c.source, c.rater_pid, c.technique, c.dosage, c.mode, c.technique_count, c.created_at,
                                          t.participant_id, t.condition_num, t.text_role
                                   FROM codings c JOIN coding_tasks t ON t.id = c.task_id
                                   ORDER BY c.id DESC");
    ?>
    <div class="card mb-3"><div class="card-body">
        <h6>All ratings (<?= count($all_codings) ?>)</h6>
        <?php if (!$all_codings): ?>
            <p class="text-muted small mb-0">No ratings collected yet.</p>
        <?php else: ?>
            <div style="max-height: 420px; overflow:auto;">
            <table class="table table-sm table-hover">
                <thead><tr><th>#</th><th>P</th><th>Cond</th><th>Role</th><th>Source</th><th>Rater</th><th>T</th><th>D</th><th>M</th><th>#Prac</th><th>When</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($all_codings as $c): ?>
                    <tr>
                        <td><?= (int)$c['id'] ?></td>
                        <td><?= (int)$c['participant_id'] ?></td>
                        <td>C<?= (int)$c['condition_num'] ?></td>
                        <td><?= htmlspecialchars($c['text_role']) ?></td>
                        <td><?= $c['source'] === 'human' ? 'human' : '<code>'.htmlspecialchars($c['source']).'</code>' ?></td>
                        <td class="small"><?= htmlspecialchars($c['rater_pid'] ?? '—') ?></td>
                        <td><?= $c['technique'] ?></td>
                        <td><?= $c['dosage'] ?></td>
                        <td><?= $c['mode'] ?></td>
                        <td><?= $c['technique_count'] === null ? '—' : ((int)$c['technique_count'] === 3 ? '3+' : (int)$c['technique_count']) ?></td>
                        <td class="small text-muted"><?= htmlspecialchars($c['created_at']) ?></td>
                        <td><a href="<?= $base_url ?>&view=coding_delete&cid=<?= (int)$c['id'] ?>" onclick="return confirm('Delete rating #<?= (int)$c['id'] ?>?')" class="text-danger text-decoration-none" title="Delete this rating">&#128465;</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </div></div>

<?php endif; ?>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
