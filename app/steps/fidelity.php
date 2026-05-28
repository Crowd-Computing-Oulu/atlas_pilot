<?php
$page_title = 'ATLAS Study — Review';

if (!isset($_SESSION['participant_id'])) {
    header('Location: ?step=consent');
    exit;
}

$condition = $_SESSION['condition'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['fidelity_data'] = [
        'semantic_fidelity' => (int)($_POST['semantic_fidelity'] ?? 0),
        'forced_fit' => (int)($_POST['forced_fit'] ?? 0),
        'fidelity_feedback' => trim($_POST['fidelity_feedback'] ?? ''),
    ];
    header('Location: ?step=exploratory');
    exit;
}

// Condition 3 has a structured gene from the refinement step
$gene = $_SESSION['current_practice'] ?? null;
$show_gene = ($condition === 3 && $gene);

require __DIR__ . '/../templates/header.php';
?>

<div class="progress-bar-custom">
    <div class="fill" style="width: <?= $progress ?>%"></div>
</div>

<div class="study-card">
    <?php if ($show_gene): ?>
        <h4 class="mb-3">Review Your Practice Description</h4>
        <p>Here's the structured summary from our conversation:</p>

        <div class="mb-4">
            <?php
            $dims = ['technique' => 'What you do', 'dosage' => 'How much', 'mode' => 'In what way'];
            foreach ($dims as $key => $label):
                $value = $gene[$key]['value'] ?? null;
            ?>
            <div class="gene-card">
                <div class="gene-label"><?= $label ?></div>
                <div class="gene-value">
                    <?php if ($value): ?>
                        <?= htmlspecialchars($value) ?>
                    <?php else: ?>
                        <span class="gene-missing">Not captured</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <h4 class="mb-3">Review Your Description</h4>
        <p>Here's what you wrote:</p>

        <div class="card bg-light mb-4">
            <div class="card-body">
                <p class="mb-0"><?= nl2br(htmlspecialchars($_SESSION['description'] ?? '')) ?></p>
            </div>
        </div>
    <?php endif; ?>

    <form method="post">
        <p class="fw-bold">How well does this capture your actual practice?</p>
        <div class="likert-group">
            <?php for ($i = 1; $i <= 7; $i++): ?>
            <label>
                <input type="radio" name="semantic_fidelity" value="<?= $i ?>" required<?= ($fill && ($syn['semantic_fidelity'] ?? null) === $i) ? ' checked' : '' ?>>
                <?= $i ?>
            </label>
            <?php endfor; ?>
        </div>
        <div class="likert-endpoints">
            <span>Not at all well</span>
            <span>Extremely well</span>
        </div>

        <p class="fw-bold">How much did you leave out, or invent or distort anything, in describing your practice?</p>
        <div class="likert-group">
            <?php for ($i = 1; $i <= 7; $i++): ?>
            <label>
                <input type="radio" name="forced_fit" value="<?= $i ?>" required<?= ($fill && ($syn['forced_fit'] ?? null) === $i) ? ' checked' : '' ?>>
                <?= $i ?>
            </label>
            <?php endfor; ?>
        </div>
        <div class="likert-endpoints">
            <span>Not at all much</span>
            <span>Extremely much</span>
        </div>

        <div class="mb-4">
            <label class="form-label">If you left out, invented, or distorted anything, briefly explain what and why. (optional)</label>
            <textarea class="form-control" name="fidelity_feedback" rows="3"><?= $fill ? htmlspecialchars($syn['fidelity_feedback'] ?? '') : '' ?></textarea>
        </div>

        <button type="submit" class="btn btn-primary btn-lg w-100">Continue</button>
    </form>
</div>

<?php require __DIR__ . '/../templates/footer.php'; ?>
