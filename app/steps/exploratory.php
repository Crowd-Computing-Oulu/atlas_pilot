<?php
$page_title = 'ATLAS Study — Context';

if (!isset($_SESSION['participant_id'])) {
    header('Location: ?step=consent');
    exit;
}

$db = get_db();

// Save fidelity data from previous step (passed via POST or stored in session)
if (!empty($_POST['semantic_fidelity']) || !empty($_REQUEST['semantic_fidelity'])) {
    // Data comes from the fidelity step redirect — store for later
    $_SESSION['fidelity_data'] = [
        'semantic_fidelity' => (int)($_REQUEST['semantic_fidelity'] ?? 0),
        'forced_fit' => (int)($_REQUEST['forced_fit'] ?? 0),
        'fidelity_feedback' => $_REQUEST['fidelity_feedback'] ?? '',
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['context_text'])) {
    $_SESSION['exploratory_data'] = [
        'context_text' => trim($_POST['context_text'] ?? ''),
        'outcome_text' => trim($_POST['outcome_text'] ?? ''),
    ];

    header('Location: ?step=questionnaire');
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
            <textarea class="form-control" name="context_text" rows="3" placeholder="e.g., After a stressful work day, when I cannot sleep, during exam periods..." required><?= $fill ? htmlspecialchars($syn['context_text'] ?? '') : '' ?></textarea>
        </div>

        <div class="mb-4">
            <label class="form-label fw-bold">What do you typically achieve as an outcome of this practice?</label>
            <textarea class="form-control" name="outcome_text" rows="3" placeholder="e.g., I feel calmer, my mind is clearer, I sleep better..." required><?= $fill ? htmlspecialchars($syn['outcome_text'] ?? '') : '' ?></textarea>
        </div>

        <button type="submit" class="btn btn-primary btn-lg w-100">Continue</button>
    </form>
</div>

<?php require __DIR__ . '/../templates/footer.php'; ?>
