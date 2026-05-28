<?php
$page_title = 'ATLAS Study — Context and Outcome';

if (!isset($_SESSION['participant_id'])) {
    header('Location: ?step=consent');
    exit;
}

// Save fidelity data from previous step (passed via POST or stored in session)
if (!empty($_POST['semantic_fidelity']) || !empty($_REQUEST['semantic_fidelity'])) {
    $_SESSION['fidelity_data'] = [
        'semantic_fidelity' => (int)($_REQUEST['semantic_fidelity'] ?? 0),
        'forced_fit' => (int)($_REQUEST['forced_fit'] ?? 0),
        'fidelity_feedback' => $_REQUEST['fidelity_feedback'] ?? '',
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['context_text'])) {
    $context_text = trim($_POST['context_text'] ?? '');
    $outcome_text = trim($_POST['outcome_text'] ?? '');
    $willingness  = (int)($_POST['willingness'] ?? 0);

    // Persist the full questionnaire row + mark participant complete. The
    // questionnaire step was merged into exploratory on 2026-05-28; the
    // interest and general_feedback columns are kept in the schema for
    // backwards compatibility and are written as NULL / empty.
    if (!$is_test) {
        $db = get_db();
        $fidelity = $_SESSION['fidelity_data'] ?? [];
        $stmt = $db->prepare('INSERT INTO questionnaire (participant_id, semantic_fidelity, forced_fit, willingness, interest, context_text, outcome_text, fidelity_feedback, general_feedback) VALUES (:pid, :sf, :ff, :w, NULL, :ctx, :out, :ffb, \'\')');
        $stmt->bindValue(':pid', $_SESSION['participant_id']);
        $stmt->bindValue(':sf',  $fidelity['semantic_fidelity'] ?? null);
        $stmt->bindValue(':ff',  $fidelity['forced_fit'] ?? null);
        $stmt->bindValue(':w',   $willingness ?: null);
        $stmt->bindValue(':ctx', $context_text);
        $stmt->bindValue(':out', $outcome_text);
        $stmt->bindValue(':ffb', $fidelity['fidelity_feedback'] ?? '');
        $stmt->execute();

        $stmt = $db->prepare('UPDATE participants SET completed_at = CURRENT_TIMESTAMP WHERE id = :pid');
        $stmt->bindValue(':pid', $_SESSION['participant_id']);
        $stmt->execute();
    }

    header('Location: ?step=debrief');
    exit;
}

require __DIR__ . '/../templates/header.php';
?>

<div class="progress-bar-custom">
    <div class="fill" style="width: <?= $progress ?>%"></div>
</div>

<div class="study-card">
    <h4 class="mb-3">Context and Outcome</h4>
    <p>We'd like to understand the broader picture of your practice.</p>

    <form method="post">
        <div class="mb-4">
            <label class="form-label fw-bold">In what kind of situation do you typically practice this?</label>
            <textarea class="form-control" name="context_text" rows="3" placeholder="Write your answer here..." required><?= $fill ? htmlspecialchars($syn['context_text'] ?? '') : '' ?></textarea>
        </div>

        <div class="mb-4">
            <label class="form-label fw-bold">What do you typically achieve as an outcome of this practice?</label>
            <textarea class="form-control" name="outcome_text" rows="3" placeholder="Write your answer here..." required><?= $fill ? htmlspecialchars($syn['outcome_text'] ?? '') : '' ?></textarea>
        </div>

        <div class="mb-4">
            <p class="fw-bold">How willing would you be to contribute descriptions like this to a public self-care knowledge base?</p>
            <div class="likert-group">
                <?php for ($i = 1; $i <= 7; $i++): ?>
                <label>
                    <input type="radio" name="willingness" value="<?= $i ?>" required<?= ($fill && ($syn['willingness'] ?? null) === $i) ? ' checked' : '' ?>>
                    <?= $i ?>
                </label>
                <?php endfor; ?>
            </div>
            <div class="likert-endpoints">
                <span>Not at all willing</span>
                <span>Very willing</span>
            </div>
        </div>

        <button type="submit" class="btn btn-primary btn-lg w-100">Finish</button>
    </form>
</div>

<?php require __DIR__ . '/../templates/footer.php'; ?>
