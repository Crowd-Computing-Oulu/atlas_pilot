<?php
/**
 * Crowd specificity-coding route (post-hoc human rating of T/D/M).
 *
 * One coding task per blinded text. Distribution: a single shared study URL
 * (code.php, no token) routes each rater to the least-rated task they have not
 * yet coded, so coverage fills evenly toward target_raters. Prolific is set to
 * "Multiple submissions" so one rater can return for several tasks; each
 * submission is one task. Direct per-task URLs (code.php?task=TOKEN) still work.
 * The coder sees ONLY the text and the rubric, never the condition or whether it
 * is a C3 first/final snapshot.
 */

require_once __DIR__ . '/db.php';

$db = get_db();
$db->busyTimeout(5000); // wait, don't fail, if another rater's serve holds the write lock
$config = require __DIR__ . '/config.php';

// Server-side rating-duration capture. The form carries a render timestamp signed
// with the app secret so a rater cannot forge a slower-looking time. Best-effort:
// a missing or invalid signature just yields a null duration, never a blocked submit.
$timing_secret = (string)($config['admin_key'] ?? '');
function timing_sig(int $t, string $token, string $secret): string {
    return hash_hmac('sha256', $t . '|' . $token, $secret);
}

$token = $_GET['task'] ?? '';
$pid = $_GET['PROLIFIC_PID'] ?? ($_POST['pid'] ?? '');
$session_id = $_GET['SESSION_ID'] ?? ($_POST['session_id'] ?? '');

// Admin demo mode: render the real coder UI and rubric with NO database writes.
// Launched from the admin dashboard (code.php?preview=1). Taskflow rater URLs never
// carry preview=1, so real raters cannot land here.
$preview = (($_GET['preview'] ?? '') === '1') || (($_POST['preview'] ?? '') === '1');

// Look up the task.
$stmt = $db->prepare("SELECT * FROM coding_tasks WHERE token = :t");
$stmt->bindValue(':t', $token, SQLITE3_TEXT);
$task = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

// In preview with no (or unknown) token, fall back to a synthetic demo text so the
// rubric can be exercised even before any tasks are seeded.
if ($preview && !$task) {
    $task = [
        'id' => 0,
        'text_content' => "When I start feeling really anxious I try to calm my breathing. "
            . "Sometimes I also go for a short walk, maybe ten minutes around the block, and I put on some quiet music. "
            . "I usually do this on my own.",
    ];
}

// Router mode: a rater landed on the shared URL with no specific task. Serve the
// least-rated open task they have not coded yet, so a single URL fills coverage
// evenly and returning raters always get a fresh text.
//
// Concurrency: the committed rating count does not move until a rater submits, so
// without protection a burst of simultaneous raters all read the same lowest task
// and dogpile it. Two defences: (1) the select-and-stamp runs in an IMMEDIATE
// transaction, so serves serialise and each sees prior stamps; (2) a task just
// handed out is deprioritised (+1 to its effective count) for ~5 min via
// last_served_at, so the next concurrent rater picks a different task. The
// reservation lapses after the TTL, so a task whose rater abandoned is not stranded.
$router_exhausted = false;
if (!$task && !$preview && $token === '') {
    $reserve_cutoff = time() - 300; // a serve softly reserves a task for ~5 min
    $count_sql = "(SELECT COUNT(*) FROM codings c WHERE c.task_id = t.id AND c.source = 'human')";
    $order_sql = "({$count_sql} + CASE WHEN t.last_served_at IS NOT NULL AND t.last_served_at >= {$reserve_cutoff} THEN 1 ELSE 0 END) ASC, RANDOM()";
    $db->exec('BEGIN IMMEDIATE');
    if ($pid !== '') {
        $sel = $db->prepare(
            "SELECT t.* FROM coding_tasks t
             WHERE {$count_sql} < t.target_raters
               AND t.id NOT IN (SELECT task_id FROM codings WHERE source = 'human' AND rater_pid = :pid)
             ORDER BY {$order_sql}
             LIMIT 1");
        $sel->bindValue(':pid', $pid, SQLITE3_TEXT);
    } else {
        // No PID (e.g. a test landing): just serve the globally least-rated open task.
        $sel = $db->prepare(
            "SELECT t.* FROM coding_tasks t
             WHERE {$count_sql} < t.target_raters
             ORDER BY {$order_sql}
             LIMIT 1");
    }
    $task = $sel->execute()->fetchArray(SQLITE3_ASSOC);
    if ($task) {
        $token = $task['token'];
        $stamp = $db->prepare("UPDATE coding_tasks SET last_served_at = :now WHERE id = :id");
        $stamp->bindValue(':now', time(), SQLITE3_INTEGER);
        $stamp->bindValue(':id', (int)$task['id'], SQLITE3_INTEGER);
        $stamp->execute();
    } else {
        // Every task is at target, or this rater has already coded all open tasks.
        $router_exhausted = true;
    }
    $db->exec('COMMIT');
}

// Rubric: dimension-specific 0-3 anchors, mirroring llm.php so human and model
// raters score against the same definitions.
$rubric = [
    'technique' => [
        'label' => 'Technique — what the person actually does',
        'levels' => [
            '0' => 'Absent — no technique mentioned',
            '1' => 'Category only — a broad family (e.g. "relaxation", "exercise")',
            '2' => 'Named — a specific named practice (e.g. "box breathing", "running")',
            '3' => 'Parameterised — a named practice with defining parameters (e.g. "4-4-4-4 box breathing")',
        ],
    ],
    'dosage' => [
        'label' => 'Dosage — the magnitude or extent of the practice',
        'levels' => [
            '0' => 'Absent — no information about magnitude or extent',
            '1' => 'Vague — non-quantified (e.g. "sometimes", "a bit", "when I need it")',
            '2' => 'Single anchor — one dose anchor of any kind (e.g. "20 minutes", "5 cycles", "3x per week", or "until I feel calmer")',
            '3' => 'Multiple anchors — two or more such anchors (e.g. "20 min, 3x per week")',
        ],
    ],
    'mode' => [
        'label' => 'Mode — how the practice is enacted (not when/why it is used)',
        'levels' => [
            '0' => 'Absent — no information about how it is enacted',
            '1' => 'Vague — minimal detail (e.g. "by myself", "with help")',
            '2' => 'Specified — a clear mode descriptor (e.g. "solo", "in a group", "with an app", "unguided")',
            '3' => 'Operationalised — mode plus a specific delivery mechanism (e.g. "solo using a meditation app for guidance")',
        ],
    ],
];

$error = '';
$done = false;

// Has this rater already coded this task? Keep coding idempotent per worker.
$already = false;
if ($task && !$preview && $pid !== '') {
    $chk = $db->prepare("SELECT 1 FROM codings WHERE task_id = :id AND source = 'human' AND rater_pid = :pid");
    $chk->bindValue(':id', $task['id'], SQLITE3_INTEGER);
    $chk->bindValue(':pid', $pid, SQLITE3_TEXT);
    $already = (bool)$chk->execute()->fetchArray(SQLITE3_ASSOC);
}

$preview_vals = null;
if ($task && $_SERVER['REQUEST_METHOD'] === 'POST' && ($preview || !$already)) {
    $vals = [];
    foreach (['technique', 'dosage', 'mode'] as $d) {
        $v = $_POST[$d] ?? '';
        if ($v === '' || !ctype_digit((string)$v) || (int)$v < 0 || (int)$v > 3) {
            $error = 'Please rate all three dimensions (0–3) before submitting.';
            break;
        }
        $vals[$d] = (int)$v;
    }
    $tc = $_POST['technique_count'] ?? '';
    if (!$error && (!ctype_digit((string)$tc) || (int)$tc < 1 || (int)$tc > 3)) {
        $error = 'Please answer how many distinct practices the text describes.';
    }
    $vals['technique_count'] = $error ? null : (int)$tc;
    if (!$error && $preview) {
        // Demo: echo the scores, write nothing.
        $preview_vals = $vals;
        $done = true;
    } elseif (!$error) {
        // Best-effort task duration: server time from render to submit, only if the
        // signed render timestamp validates and the delta is sane (0..24h).
        $coding_seconds = null;
        $rat = $_POST['rendered_at'] ?? '';
        $rsig = (string)($_POST['rendered_sig'] ?? '');
        if ($timing_secret !== '' && ctype_digit((string)$rat)
            && hash_equals(timing_sig((int)$rat, $token, $timing_secret), $rsig)) {
            $delta = time() - (int)$rat;
            if ($delta >= 0 && $delta < 86400) {
                $coding_seconds = $delta;
            }
        }
        $ins = $db->prepare(
            "INSERT INTO codings (task_id, source, rater_pid, session_id, technique, dosage, mode, technique_count, coding_seconds)
             VALUES (:tid, 'human', :pid, :sid, :t, :d, :m, :tc, :cs)"
        );
        $ins->bindValue(':tid', $task['id'], SQLITE3_INTEGER);
        $ins->bindValue(':pid', $pid !== '' ? $pid : null, $pid !== '' ? SQLITE3_TEXT : SQLITE3_NULL);
        $ins->bindValue(':sid', $session_id !== '' ? $session_id : null, $session_id !== '' ? SQLITE3_TEXT : SQLITE3_NULL);
        $ins->bindValue(':t', $vals['technique'], SQLITE3_INTEGER);
        $ins->bindValue(':d', $vals['dosage'], SQLITE3_INTEGER);
        $ins->bindValue(':m', $vals['mode'], SQLITE3_INTEGER);
        $ins->bindValue(':tc', $vals['technique_count'], SQLITE3_INTEGER);
        $ins->bindValue(':cs', $coding_seconds, $coding_seconds === null ? SQLITE3_NULL : SQLITE3_INTEGER);
        $ins->execute();
        $done = true;
    }
}

// Render timestamp for the form. On a validation retry, preserve the original
// signed value so the clock measures the whole rating, not just the last attempt.
$posted_rat = $_POST['rendered_at'] ?? '';
$posted_sig = (string)($_POST['rendered_sig'] ?? '');
if ($timing_secret !== '' && $token !== '' && ctype_digit((string)$posted_rat)
    && hash_equals(timing_sig((int)$posted_rat, $token, $timing_secret), $posted_sig)) {
    $rendered_at = (int)$posted_rat;
} else {
    $rendered_at = time();
}
$rendered_sig = ($token !== '' && $timing_secret !== '') ? timing_sig($rendered_at, $token, $timing_secret) : '';

$page_title = 'ATLAS — Specificity Coding';
require __DIR__ . '/templates/header.php';

if ($router_exhausted):
    $return_url = ($config['coding_completion_url'] ?? '') ?: (($config['prolific_completion_url'] ?? '') ?: 'https://app.prolific.com/submissions/complete');
    ?>
    <div class="study-card">
        <h4 class="mb-3">All caught up</h4>
        <p>There are no more texts available for you to rate right now. Thank you for your work.</p>
        <a href="<?= htmlspecialchars($return_url) ?>" class="btn btn-primary btn-lg w-100 mt-2">Return to Prolific</a>
    </div>
    <?php
elseif (!$task):
    ?>
    <div class="study-card">
        <h4>Task not found</h4>
        <p>This coding link is not valid. Please return to Prolific and contact the researcher if the problem persists.</p>
    </div>
    <?php
elseif ($done && $preview):
    ?>
    <div class="study-card">
        <div class="alert alert-info">Preview mode — nothing was saved to the database.</div>
        <h4 class="mb-3">These scores would be recorded</h4>
        <table class="table table-sm w-auto">
            <tr><td>Technique</td><td><strong><?= (int)$preview_vals['technique'] ?></strong> / 3</td></tr>
            <tr><td>Dosage</td><td><strong><?= (int)$preview_vals['dosage'] ?></strong> / 3</td></tr>
            <tr><td>Mode</td><td><strong><?= (int)$preview_vals['mode'] ?></strong> / 3</td></tr>
            <tr><td>Distinct practices</td><td><strong><?= (int)$preview_vals['technique_count'] === 3 ? '3+' : (int)$preview_vals['technique_count'] ?></strong></td></tr>
        </table>
        <a href="code.php?preview=1<?= $token !== '' ? '&task=' . htmlspecialchars(urlencode($token)) : '' ?>" class="btn btn-outline-primary mt-2">Run the demo again</a>
    </div>
    <?php
elseif ($done || $already):
    $return_url = ($config['coding_completion_url'] ?? '') ?: (($config['prolific_completion_url'] ?? '') ?: 'https://app.prolific.com/submissions/complete');
    ?>
    <div class="study-card">
        <h4 class="mb-3">Thank you!</h4>
        <p>Your rating has been recorded.<?= $already ? ' (You had already rated this text.)' : '' ?></p>
        <a href="<?= htmlspecialchars($return_url) ?>" class="btn btn-primary btn-lg w-100 mt-2">Return to Prolific</a>
    </div>
    <?php
else:
    ?>
    <div class="study-card">
        <?php if ($preview): ?><div class="alert alert-info">Preview mode — this is exactly what a crowd rater sees. Submitting saves nothing.</div><?php endif; ?>
        <h4 class="mb-2">Rate how specifically this text describes a self-care practice</h4>
        <p class="text-muted small">A person was asked to describe a practice they use when feeling stressed or anxious. Read their description, then rate how specific it is on three dimensions using the guides. Rate only what is written; do not guess what they might have meant.</p>
        <p class="small"><strong>If the text describes more than one practice</strong> (for example breathing <em>and</em> a walk), rate the <strong>main practice</strong> only: the one the writer leads with or describes in the most detail. You will note how many separate practices there are in the last question.</p>

        <div class="card bg-light my-3">
            <div class="card-body">
                <div style="white-space: pre-wrap; font-size: 1.05rem;"><?= nl2br(htmlspecialchars($task['text_content'])) ?></div>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-warning"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" action="code.php?<?= $preview ? 'preview=1&' : '' ?>task=<?= htmlspecialchars(urlencode($token)) ?>">
            <input type="hidden" name="pid" value="<?= htmlspecialchars($pid) ?>">
            <input type="hidden" name="session_id" value="<?= htmlspecialchars($session_id) ?>">
            <input type="hidden" name="rendered_at" value="<?= (int)$rendered_at ?>">
            <input type="hidden" name="rendered_sig" value="<?= htmlspecialchars($rendered_sig) ?>">
            <?php if ($preview): ?><input type="hidden" name="preview" value="1"><?php endif; ?>

            <?php foreach ($rubric as $dim => $info): ?>
                <fieldset class="mb-4">
                    <legend class="fs-6 fw-bold"><?= htmlspecialchars($info['label']) ?></legend>
                    <?php foreach ($info['levels'] as $score => $desc): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="<?= $dim ?>" id="<?= $dim ?>_<?= $score ?>" value="<?= $score ?>"
                                   <?= (($_POST[$dim] ?? '') === $score) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="<?= $dim ?>_<?= $score ?>">
                                <strong><?= $score ?></strong> — <?= htmlspecialchars($desc) ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </fieldset>
            <?php endforeach; ?>

            <fieldset class="mb-4">
                <legend class="fs-6 fw-bold">How many distinct practices does the text describe?</legend>
                <p class="small text-muted mb-2">Count separate things the person does (e.g. breathing and a walk = 2). You rated only the main one above.</p>
                <?php foreach (['1' => '1 practice', '2' => '2 practices', '3' => '3 or more practices'] as $cscore => $clabel): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="technique_count" id="tc_<?= $cscore ?>" value="<?= $cscore ?>"
                               <?= (($_POST['technique_count'] ?? '') === $cscore) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="tc_<?= $cscore ?>"><strong><?= $cscore === '3' ? '3+' : $cscore ?></strong> — <?= htmlspecialchars($clabel) ?></label>
                    </div>
                <?php endforeach; ?>
            </fieldset>

            <button type="submit" class="btn btn-primary btn-lg w-100">Submit rating</button>
        </form>
    </div>
    <?php
endif;

require __DIR__ . '/templates/footer.php';
