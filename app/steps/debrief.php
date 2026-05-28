<?php
$page_title = 'ATLAS Study — Thank You';

if (!isset($_SESSION['participant_id'])) {
    header('Location: ?step=consent');
    exit;
}

$config = require __DIR__ . '/../config.php';
$completion_code = $_SESSION['completion_code'] ?? 'ERROR';
$source = $_SESSION['source'] ?? 'web';
$prolific_url = $config['prolific_completion_url'];

require __DIR__ . '/../templates/header.php';
?>

<div class="progress-bar-custom">
    <div class="fill" style="width: 100%"></div>
</div>

<div class="study-card">
    <h4 class="mb-3">Thank You!</h4>

    <p>You have completed the ATLAS study. Your contribution helps us understand how people describe their self-care practices and will inform the design of a citizen-science platform for self-care research.</p>

    <h5 class="mt-4">About This Study</h5>
    <p>The ATLAS project is developing a formal language for everyday self-care. We describe each practice as a structured combination of three primitives: what you do, how much, and in what way. Your description is now part of the first seed atlas of self-care practices.</p>

    <p>In the future, we aim to build an open platform where people can contribute their practices, explore what works for others in similar situations, and collectively build a science of everyday wellbeing.</p>

    <?php if ($source === 'prolific' || $source === 'test'): ?>
        <div class="alert alert-success mt-4">
            <strong>Return to Prolific to register your participation.</strong>
            <p class="mt-2 mb-0">Click the button below. Prolific will record your submission as complete; you do not need to type a code anywhere.</p>
        </div>
        <a href="<?= htmlspecialchars($prolific_url) ?>" class="btn btn-primary btn-lg w-100 mt-2">Return to Prolific</a>
        <p class="text-muted small mt-3">For our internal records, your reference code is <span class="font-monospace"><?= htmlspecialchars($completion_code) ?></span>. You can ignore this unless you need to contact us about your participation.</p>
    <?php else: ?>
        <div class="alert alert-success mt-4">
            <strong>Your completion code:</strong>
            <div class="fs-4 font-monospace mt-1"><?= htmlspecialchars($completion_code) ?></div>
            <p class="mt-2 mb-0 small">Use this if you need to contact us about your participation.</p>
        </div>
    <?php endif; ?>

    <p class="text-muted mt-4 small">If you have questions about this study, contact: simo.hosio@oulu.fi</p>
</div>

<?php
// Clear session
session_destroy();
require __DIR__ . '/../templates/footer.php';
?>
