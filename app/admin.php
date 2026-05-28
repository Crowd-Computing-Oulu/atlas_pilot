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
    $allowed = ['participants', 'responses', 'gene_extractions', 'questionnaire'];
    if (!in_array($table, $allowed)) die('Invalid table');

    // PHP's SQLite3Result does not have fetchAll(); build rows via fetchArray loop.
    if ($table === 'participants') {
        $sql = "SELECT * FROM participants ORDER BY id";
    } elseif ($table === 'responses') {
        $sql = "SELECT r.*, p.prolific_pid, p.condition_num FROM responses r JOIN participants p ON r.participant_id = p.id ORDER BY r.id";
    } elseif ($table === 'gene_extractions') {
        $sql = "SELECT g.*, p.prolific_pid, p.condition_num FROM gene_extractions g JOIN participants p ON g.participant_id = p.id ORDER BY g.id";
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
        fputcsv($out, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        fclose($out);
    }
    exit;
}

// Handle participant deletion
if ($view === 'delete' && isset($_GET['id'])) {
    $del_id = (int)$_GET['id'];
    $db->exec("DELETE FROM questionnaire WHERE participant_id = {$del_id}");
    $db->exec("DELETE FROM gene_extractions WHERE participant_id = {$del_id}");
    $db->exec("DELETE FROM responses WHERE participant_id = {$del_id}");
    $db->exec("DELETE FROM participants WHERE id = {$del_id}");
    header("Location: {$base_url}&view=overview");
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

// Helper to run a query and fetch all rows
function fetch_all(SQLite3 $db, string $sql): array {
    $result = $db->query($sql);
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }
    return $rows;
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
            <div class="btn-group">
                <button type="button" class="btn btn-sm btn-outline-success dropdown-toggle" data-bs-toggle="dropdown">Export</button>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="<?= $base_url ?>&view=export&table=participants">Participants CSV</a></li>
                    <li><a class="dropdown-item" href="<?= $base_url ?>&view=export&table=responses">Responses CSV</a></li>
                    <li><a class="dropdown-item" href="<?= $base_url ?>&view=export&table=gene_extractions">Gene Extractions CSV</a></li>
                    <li><a class="dropdown-item" href="<?= $base_url ?>&view=export&table=questionnaire">Questionnaire CSV</a></li>
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
                <thead><tr><th>ID</th><th>PID</th><th>Source</th><th>Condition</th><th>Started</th><th>Completed</th><th>Code</th></tr></thead>
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
    $extractions = fetch_all($db, "SELECT * FROM gene_extractions WHERE participant_id = {$pid} ORDER BY round");
    $q = $db->querySingle("SELECT * FROM questionnaire WHERE participant_id = {$pid}", true);

    $cond_labels = [1 => 'Baseline', 2 => 'Nudge', 3 => 'AI Coach'];
    ?>

    <a href="<?= $base_url ?>&view=participants" class="btn btn-sm btn-outline-secondary mb-3">&larr; Back</a>

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
            <h6>Gene Extractions (Refinement Trajectory)</h6>
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
                <tr><td>Self-reported Distortion</td><td><?= $q['forced_fit'] ?>/7</td></tr>
                <tr><td>Willingness to Contribute</td><td><?= $q['willingness'] ?>/7</td></tr>
                <?php
                    $ac = $q['attention_check'] ?? null;
                    $ac_label = $ac === null ? 'n/a' : (((int)$ac === 1) ? "PASS ({$ac}/7)" : "FAIL ({$ac}/7)");
                    $ac_class = $ac === null ? '' : (((int)$ac === 1) ? 'text-success fw-bold' : 'text-danger fw-bold');
                ?>
                <tr><td>Attention Check</td><td class="<?= $ac_class ?>"><?= $ac_label ?></td></tr>
                <tr><td>Interest</td><td><?= $q['interest'] ?>/7</td></tr>
                <tr><td>Context</td><td><?= htmlspecialchars($q['context_text'] ?: '—') ?></td></tr>
                <tr><td>Outcome</td><td><?= htmlspecialchars($q['outcome_text'] ?: '—') ?></td></tr>
                <tr><td>Fidelity Feedback</td><td><?= htmlspecialchars($q['fidelity_feedback'] ?: '—') ?></td></tr>
                <tr><td>General Feedback</td><td><?= htmlspecialchars($q['general_feedback'] ?: '—') ?></td></tr>
            </table>
        </div>
    </div>
    <?php endif; ?>

<?php endif; ?>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
