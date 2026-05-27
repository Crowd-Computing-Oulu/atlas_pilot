<?php
$page_title = 'ATLAS Study — A Few Questions';

if (!isset($_SESSION['participant_id'])) {
    header('Location: ?step=consent');
    exit;
}

$pss_items = [
    'pss4_q1' => 'In the last month, how often have you felt that you were unable to control the important things in your life?',
    'pss4_q2' => 'In the last month, how often have you felt confident about your ability to handle your personal problems?',
    'pss4_q3' => 'In the last month, how often have you felt that things were going your way?',
    'pss4_q4' => 'In the last month, how often have you felt difficulties were piling up so high that you could not overcome them?',
];

$pss_options = [
    0 => 'Never',
    1 => 'Almost never',
    2 => 'Sometimes',
    3 => 'Fairly often',
    4 => 'Very often',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values = [];
    $valid = true;
    foreach (array_keys($pss_items) as $key) {
        if (!isset($_POST[$key]) || $_POST[$key] === '') {
            $valid = false;
            break;
        }
        $v = (int)$_POST[$key];
        if ($v < 0 || $v > 4) { $valid = false; break; }
        $values[$key] = $v;
    }

    if (!$valid) {
        $error = 'Please answer all four questions.';
    } else {
        // PSS-4 scoring: q2 and q3 are reverse-scored.
        $sum = $values['pss4_q1'] + (4 - $values['pss4_q2']) + (4 - $values['pss4_q3']) + $values['pss4_q4'];

        if (!$is_test) {
            $db = get_db();
            $stmt = $db->prepare('UPDATE participants SET pss4_q1 = :q1, pss4_q2 = :q2, pss4_q3 = :q3, pss4_q4 = :q4, pss4_sum = :sum WHERE id = :pid');
            $stmt->bindValue(':q1', $values['pss4_q1']);
            $stmt->bindValue(':q2', $values['pss4_q2']);
            $stmt->bindValue(':q3', $values['pss4_q3']);
            $stmt->bindValue(':q4', $values['pss4_q4']);
            $stmt->bindValue(':sum', $sum);
            $stmt->bindValue(':pid', $_SESSION['participant_id']);
            $stmt->execute();
        }

        $next = (($_SESSION['condition'] ?? 0) === 3) ? 'refinement' : 'input';
        header('Location: ?step=' . $next);
        exit;
    }
}

require __DIR__ . '/../templates/header.php';
?>

<div class="progress-bar-custom">
    <div class="fill" style="width: <?= $progress ?>%"></div>
</div>

<div class="study-card">
    <h4 class="mb-3">A few quick questions about how you've been feeling</h4>
    <p class="text-muted">These help us describe who took part. Your answers do not affect your eligibility or compensation.</p>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <?php foreach ($pss_items as $name => $question): ?>
        <div class="mb-4">
            <p class="fw-bold mb-2"><?= htmlspecialchars($question) ?></p>
            <div class="d-flex flex-column gap-1">
                <?php foreach ($pss_options as $value => $label): ?>
                <label class="d-flex align-items-center gap-2">
                    <input type="radio" name="<?= $name ?>" value="<?= $value ?>" required<?= ($fill && ($syn['pss4'][$name] ?? null) === $value) ? ' checked' : '' ?>>
                    <span><?= htmlspecialchars($label) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <button type="submit" class="btn btn-primary btn-lg w-100">Continue</button>
    </form>
</div>

<?php require __DIR__ . '/../templates/footer.php'; ?>
