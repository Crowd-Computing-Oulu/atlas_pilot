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

$gad_items = [
    'gad2_q1' => 'Feeling nervous, anxious, or on edge',
    'gad2_q2' => 'Not being able to stop or control worrying',
];

$gad_options = [
    0 => 'Not at all',
    1 => 'Several days',
    2 => 'More than half the days',
    3 => 'Nearly every day',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pss_values = [];
    $gad_values = [];
    $valid = true;

    foreach (array_keys($pss_items) as $key) {
        if (!isset($_POST[$key]) || $_POST[$key] === '') { $valid = false; break; }
        $v = (int)$_POST[$key];
        if ($v < 0 || $v > 4) { $valid = false; break; }
        $pss_values[$key] = $v;
    }

    if ($valid) {
        foreach (array_keys($gad_items) as $key) {
            if (!isset($_POST[$key]) || $_POST[$key] === '') { $valid = false; break; }
            $v = (int)$_POST[$key];
            if ($v < 0 || $v > 3) { $valid = false; break; }
            $gad_values[$key] = $v;
        }
    }

    if (!$valid) {
        $error = 'Please answer all of the questions.';
    } else {
        // PSS-4 scoring: q2 and q3 are reverse-scored. Range 0-16.
        $pss_sum = $pss_values['pss4_q1'] + (4 - $pss_values['pss4_q2']) + (4 - $pss_values['pss4_q3']) + $pss_values['pss4_q4'];
        // GAD-2 scoring: both items direct. Range 0-6.
        $gad_sum = $gad_values['gad2_q1'] + $gad_values['gad2_q2'];

        if (!$is_test) {
            $db = get_db();
            $stmt = $db->prepare('UPDATE participants SET pss4_q1 = :p1, pss4_q2 = :p2, pss4_q3 = :p3, pss4_q4 = :p4, pss4_sum = :psum, gad2_q1 = :g1, gad2_q2 = :g2, gad2_sum = :gsum WHERE id = :pid');
            $stmt->bindValue(':p1', $pss_values['pss4_q1']);
            $stmt->bindValue(':p2', $pss_values['pss4_q2']);
            $stmt->bindValue(':p3', $pss_values['pss4_q3']);
            $stmt->bindValue(':p4', $pss_values['pss4_q4']);
            $stmt->bindValue(':psum', $pss_sum);
            $stmt->bindValue(':g1', $gad_values['gad2_q1']);
            $stmt->bindValue(':g2', $gad_values['gad2_q2']);
            $stmt->bindValue(':gsum', $gad_sum);
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

        <h6 class="text-muted text-uppercase small mt-2 mb-3">Part 1 &mdash; over the last month</h6>

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

        <h6 class="text-muted text-uppercase small mt-4 mb-2">Part 2 &mdash; over the last 2 weeks</h6>
        <p class="text-muted mb-3">How often have you been bothered by the following problems?</p>

        <?php foreach ($gad_items as $name => $question): ?>
        <div class="mb-4">
            <p class="fw-bold mb-2"><?= htmlspecialchars($question) ?></p>
            <div class="d-flex flex-column gap-1">
                <?php foreach ($gad_options as $value => $label): ?>
                <label class="d-flex align-items-center gap-2">
                    <input type="radio" name="<?= $name ?>" value="<?= $value ?>" required<?= ($fill && ($syn['gad2'][$name] ?? null) === $value) ? ' checked' : '' ?>>
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
