<?php
$page_title = 'ATLAS Study — Your Practice';

if (!isset($_SESSION['participant_id'])) {
    header('Location: ?step=consent');
    exit;
}

$condition = $_SESSION['condition'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $description = trim($_POST['description'] ?? '');
    if (strlen($description) < 10) {
        $error = 'Please write at least a few sentences about your practice.';
    } else {
        $_SESSION['description'] = $description;

        // Determine which prompt was shown
        $prompt = ($condition === 2)
            ? 'Think of something you do specifically when you are feeling stressed or anxious to help yourself feel better. Try to describe: what exactly you do, how much or how often, and in what way or setting.'
            : 'Think of something you do specifically when you are feeling stressed or anxious to help yourself feel better. Describe it in your own words. Tell us whatever feels important about what you do.';

        if (!$is_test) {
            $db = get_db();
            $stmt = $db->prepare('INSERT INTO responses (participant_id, step, prompt_shown, response_text) VALUES (:pid, :step, :prompt, :text)');
            $stmt->bindValue(':pid', $_SESSION['participant_id']);
            $stmt->bindValue(':step', 'initial_description');
            $stmt->bindValue(':prompt', $prompt);
            $stmt->bindValue(':text', $description);
            $stmt->execute();
        }

        // For Condition 3, go to refinement. For 1 & 2, go to fidelity.
        if ($condition === 3) {
            header('Location: ?step=refinement&round=0');
        } else {
            header('Location: ?step=fidelity');
        }
        exit;
    }
}

require __DIR__ . '/../templates/header.php';
?>

<div class="progress-bar-custom">
    <div class="fill" style="width: <?= $progress ?>%"></div>
</div>

<div class="study-card">
    <h4 class="mb-3">Your Practice</h4>

    <p class="lead">Think of something you do <strong>specifically when you are feeling stressed or anxious</strong> to help yourself feel better.</p>

    <?php if ($condition === 2): ?>
        <p>Try to describe: <strong>what exactly you do</strong>, <strong>how much or how often</strong>, and <strong>in what way or setting</strong>.</p>
    <?php else: ?>
        <p>Describe it in your own words. Tell us whatever feels important about what you do.</p>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <textarea class="form-control mb-3" name="description" rows="6" placeholder="Write about your practice here..."><?= htmlspecialchars($_POST['description'] ?? ($syn['description'] ?? '')) ?></textarea>
        <button type="submit" class="btn btn-primary btn-lg w-100">Continue</button>
    </form>
</div>

<?php require __DIR__ . '/../templates/footer.php'; ?>
