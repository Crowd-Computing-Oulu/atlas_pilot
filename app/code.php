<?php
/**
 * Crowd specificity-coding route (post-hoc human rating of T/D/M).
 *
 * One coding task per blinded text. Distributed via Prolific Taskflow: each task
 * is a unique URL (code.php?task=TOKEN) with a per-task rater allocation, so two
 * (or more) independent raters score every text. The coder sees ONLY the text and
 * the rubric, never the condition or whether it is a C3 first/final snapshot.
 */

require_once __DIR__ . '/db.php';

$db = get_db();
$config = require __DIR__ . '/config.php';

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
        $ins = $db->prepare(
            "INSERT INTO codings (task_id, source, rater_pid, session_id, technique, dosage, mode, technique_count, notes)
             VALUES (:tid, 'human', :pid, :sid, :t, :d, :m, :tc, :notes)"
        );
        $ins->bindValue(':tid', $task['id'], SQLITE3_INTEGER);
        $ins->bindValue(':pid', $pid !== '' ? $pid : null, $pid !== '' ? SQLITE3_TEXT : SQLITE3_NULL);
        $ins->bindValue(':sid', $session_id !== '' ? $session_id : null, $session_id !== '' ? SQLITE3_TEXT : SQLITE3_NULL);
        $ins->bindValue(':t', $vals['technique'], SQLITE3_INTEGER);
        $ins->bindValue(':d', $vals['dosage'], SQLITE3_INTEGER);
        $ins->bindValue(':m', $vals['mode'], SQLITE3_INTEGER);
        $ins->bindValue(':tc', $vals['technique_count'], SQLITE3_INTEGER);
        $ins->bindValue(':notes', trim($_POST['notes'] ?? '') ?: null, SQLITE3_TEXT);
        $ins->execute();
        $done = true;
    }
}

$page_title = 'ATLAS — Specificity Coding';
require __DIR__ . '/templates/header.php';

if (!$task):
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

            <div class="mb-3">
                <label class="form-label small text-muted" for="notes">Optional: anything ambiguous about this text?</label>
                <textarea class="form-control" id="notes" name="notes" rows="2"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
            </div>

            <button type="submit" class="btn btn-primary btn-lg w-100">Submit rating</button>
        </form>
    </div>
    <?php
endif;

require __DIR__ . '/templates/footer.php';
