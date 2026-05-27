<?php
$page_title = 'ATLAS Study — Final Questions';

if (!isset($_SESSION['participant_id'])) {
    header('Location: ?step=consent');
    exit;
}

$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Save all questionnaire data (fidelity + exploratory + this step)
    $fidelity = $_SESSION['fidelity_data'] ?? [];
    $exploratory = $_SESSION['exploratory_data'] ?? [];

    if (!$is_test) {
        $db = get_db();
        $stmt = $db->prepare('INSERT INTO questionnaire (participant_id, semantic_fidelity, forced_fit, willingness, interest, context_text, outcome_text, fidelity_feedback, general_feedback) VALUES (:pid, :sf, :ff, :w, :i, :ctx, :out, :ffb, :gfb)');
        $stmt->bindValue(':pid', $_SESSION['participant_id']);
        $stmt->bindValue(':sf', $fidelity['semantic_fidelity'] ?? null);
        $stmt->bindValue(':ff', $fidelity['forced_fit'] ?? null);
        $stmt->bindValue(':w', (int)($_POST['willingness'] ?? 0));
        $stmt->bindValue(':i', (int)($_POST['interest'] ?? 0));
        $stmt->bindValue(':ctx', $exploratory['context_text'] ?? '');
        $stmt->bindValue(':out', $exploratory['outcome_text'] ?? '');
        $stmt->bindValue(':ffb', $fidelity['fidelity_feedback'] ?? '');
        $stmt->bindValue(':gfb', trim($_POST['general_feedback'] ?? ''));
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
    <h4 class="mb-3">A Few Final Questions</h4>

    <form method="post">
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

        <p class="fw-bold">How interesting did you find this activity?</p>
        <div class="likert-group">
            <?php for ($i = 1; $i <= 7; $i++): ?>
            <label>
                <input type="radio" name="interest" value="<?= $i ?>" required<?= ($fill && ($syn['interest'] ?? null) === $i) ? ' checked' : '' ?>>
                <?= $i ?>
            </label>
            <?php endfor; ?>
        </div>
        <div class="likert-endpoints">
            <span>Not at all interesting</span>
            <span>Very interesting</span>
        </div>

        <div class="mb-4">
            <label class="form-label fw-bold">Any thoughts on the experience? (optional)</label>
            <textarea class="form-control" name="general_feedback" rows="3" placeholder="Anything you'd like to share about this study..."><?= $fill ? htmlspecialchars($syn['general_feedback'] ?? '') : '' ?></textarea>
        </div>

        <button type="submit" class="btn btn-primary btn-lg w-100">Finish</button>
    </form>
</div>

<?php require __DIR__ . '/../templates/footer.php'; ?>
