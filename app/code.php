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

// Look up the task.
$stmt = $db->prepare("SELECT * FROM coding_tasks WHERE token = :t");
$stmt->bindValue(':t', $token, SQLITE3_TEXT);
$task = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

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
            '2' => 'Single parameter — one quantitative anchor (e.g. "20 minutes", "5 cycles", "3x per week")',
            '3' => 'Multi parameter — two or more quantitative anchors (e.g. "20 min, 3x per week")',
        ],
    ],
    'mode' => [
        'label' => 'Mode — how the practice is enacted (not when/why it is used)',
        'levels' => [
            '0' => 'Absent — no information about how it is enacted',
            '1' => 'Vague — minimal detail (e.g. "by myself", "with help")',
            '2' => 'Specified — a clear mode descriptor (e.g. "solo", "in a group", "with an app", "unguided")',
            '3' => 'Operationalised — mode plus a specific delivery mechanism (e.g. "solo using the Headspace app")',
        ],
    ],
];

$error = '';
$done = false;

// Has this rater already coded this task? Keep coding idempotent per worker.
$already = false;
if ($task && $pid !== '') {
    $chk = $db->prepare("SELECT 1 FROM codings WHERE task_id = :id AND source = 'human' AND rater_pid = :pid");
    $chk->bindValue(':id', $task['id'], SQLITE3_INTEGER);
    $chk->bindValue(':pid', $pid, SQLITE3_TEXT);
    $already = (bool)$chk->execute()->fetchArray(SQLITE3_ASSOC);
}

if ($task && $_SERVER['REQUEST_METHOD'] === 'POST' && !$already) {
    $vals = [];
    foreach (['technique', 'dosage', 'mode'] as $d) {
        $v = $_POST[$d] ?? '';
        if ($v === '' || !ctype_digit((string)$v) || (int)$v < 0 || (int)$v > 3) {
            $error = 'Please rate all three dimensions (0–3) before submitting.';
            break;
        }
        $vals[$d] = (int)$v;
    }
    if (!$error) {
        $ins = $db->prepare(
            "INSERT INTO codings (task_id, source, rater_pid, session_id, technique, dosage, mode, notes)
             VALUES (:tid, 'human', :pid, :sid, :t, :d, :m, :notes)"
        );
        $ins->bindValue(':tid', $task['id'], SQLITE3_INTEGER);
        $ins->bindValue(':pid', $pid !== '' ? $pid : null, $pid !== '' ? SQLITE3_TEXT : SQLITE3_NULL);
        $ins->bindValue(':sid', $session_id !== '' ? $session_id : null, $session_id !== '' ? SQLITE3_TEXT : SQLITE3_NULL);
        $ins->bindValue(':t', $vals['technique'], SQLITE3_INTEGER);
        $ins->bindValue(':d', $vals['dosage'], SQLITE3_INTEGER);
        $ins->bindValue(':m', $vals['mode'], SQLITE3_INTEGER);
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
        <h4 class="mb-2">Rate how specifically this text describes a self-care practice</h4>
        <p class="text-muted small">A person was asked to describe a practice they use when feeling stressed or anxious. Read their description, then rate how specific it is on three dimensions using the guides. Rate only what is written; do not guess what they might have meant.</p>

        <div class="card bg-light my-3">
            <div class="card-body">
                <div style="white-space: pre-wrap; font-size: 1.05rem;"><?= nl2br(htmlspecialchars($task['text_content'])) ?></div>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-warning"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" action="code.php?task=<?= htmlspecialchars(urlencode($token)) ?>">
            <input type="hidden" name="pid" value="<?= htmlspecialchars($pid) ?>">
            <input type="hidden" name="session_id" value="<?= htmlspecialchars($session_id) ?>">

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
