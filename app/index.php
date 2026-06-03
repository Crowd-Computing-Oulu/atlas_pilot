<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/synthetic.php';

$step = $_GET['step'] ?? 'consent';

// Admin test / preview entries always start from a clean slate so repeated clicks
// don't inherit prior walk state (participant_id=-1, condition, step session vars
// like current_practice / fidelity_data, etc.). Plain Prolific links don't pass
// test/fill, so this never fires for real participants.
if ($step === 'consent' && (isset($_GET['test']) || isset($_GET['fill']))) {
    $_SESSION = [];
}

// Handle forced condition for testing (e.g., ?condition=2)
if (isset($_GET['condition']) && $step === 'consent') {
    $_SESSION['forced_condition'] = (int)$_GET['condition'];
}

// Researcher preview: ?fill=1 pre-fills every form with synthetic data and implies
// test mode (no DB writes). Set/clear on each consent entry so a plain test link used
// after a preview does not inherit a stale fill flag from the session.
if ($step === 'consent') {
    $_SESSION['fill'] = isset($_GET['fill']);
}

// Handle Prolific PID or generate web/test PID
if ($step === 'consent' && !isset($_SESSION['participant_id'])) {
    $prolific_pid = $_GET['PROLIFIC_PID'] ?? null;
    if ($prolific_pid) {
        $_SESSION['source'] = 'prolific';
        $_SESSION['prolific_pid'] = $prolific_pid;
    } else {
        $_SESSION['source'] = 'web';
        $_SESSION['prolific_pid'] = 'web_' . bin2hex(random_bytes(4));
    }

    // Test mode: test_ prefix means no database writes. Preview (fill) implies test.
    // Both params are preserved on the consent form POST (the form has no action), so
    // source is set correctly for the whole flow.
    if (isset($_GET['test']) || isset($_GET['fill'])) {
        $_SESSION['prolific_pid'] = 'test_' . bin2hex(random_bytes(4));
        $_SESSION['source'] = 'test';
    }
}

// Check if this is a test session (no DB writes)
$is_test = ($_SESSION['source'] ?? '') === 'test';

// Researcher preview: when on, each step renders pre-filled with synthetic values.
$fill = !empty($_SESSION['fill']);
$syn = $fill ? synthetic_preview() : [];

// Calculate progress for progress bar. The description step is `input` for C1/C2
// and `refinement` for C3; without a condition-specific list those occupy different
// indices and C3 reads as further along at the same stage. Use one list per
// condition so the description step sits at the same position for everyone.
$cond = (int)($_SESSION['condition'] ?? $_SESSION['forced_condition'] ?? 0);
$steps_order = $cond === 3
    ? ['consent', 'intake', 'refinement', 'fidelity', 'exploratory', 'debrief']
    : ['consent', 'intake', 'input', 'fidelity', 'exploratory', 'debrief'];
$current_index = array_search($step, $steps_order);
$total_steps = count($steps_order);
$progress = $current_index !== false ? (($current_index) / ($total_steps - 1)) * 100 : 0;

// Route to step
$step_file = __DIR__ . '/steps/' . basename($step) . '.php';
if (file_exists($step_file)) {
    require $step_file;
} else {
    header('Location: ?step=consent');
    exit;
}
