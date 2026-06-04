<?php
$page_title = 'ATLAS Study — Your Practice';
require_once __DIR__ . '/../llm.php';

if (!isset($_SESSION['participant_id']) || ($_SESSION['condition'] ?? 0) !== 3) {
    header('Location: ?step=consent');
    exit;
}

const C3_MAX_ANALYSES = 5;
$prompt_text = 'Think of something you do specifically when you are feeling stressed or anxious to help yourself feel better. Describe it in your own words.';

$analyses = (int)($_SESSION['c3_analyses'] ?? 0);
$practice = $_SESSION['current_practice'] ?? null;
$desc = $_SESSION['description'] ?? '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $desc = trim($_POST['description'] ?? '');

    if ($action === 'analyse') {
        if (strlen($desc) < 10) {
            $error = 'Please write at least a few sentences about your practice first.';
        } elseif ($analyses >= C3_MAX_ANALYSES) {
            $error = 'You have reached the maximum number of checks. Continue whenever your description feels accurate.';
        } else {
            $_SESSION['description'] = $desc;
            $result = analyse_practice($desc);
            if (!$result) {
                // Graceful fallback: do not block the participant if the API fails.
                $result = [
                    'technique' => ['value' => null, 'level' => 0, 'hint' => ''],
                    'dosage'    => ['value' => null, 'level' => 0, 'hint' => ''],
                    'mode'      => ['value' => null, 'level' => 0, 'hint' => ''],
                ];
            }
            $round = $analyses; // 0 = first/initial analysis

            if (!$is_test) {
                $db = get_db();
                $stmt = $db->prepare('INSERT INTO responses (participant_id, step, prompt_shown, response_text) VALUES (:pid, :step, :prompt, :text)');
                $stmt->bindValue(':pid', $_SESSION['participant_id']);
                $stmt->bindValue(':step', $round === 0 ? 'initial_description' : ('c3_text_r' . $round));
                $stmt->bindValue(':prompt', $prompt_text);
                $stmt->bindValue(':text', $desc);
                $stmt->execute();

                $stmt = $db->prepare('INSERT INTO practice_extractions (participant_id, round, technique, dosage, mode, technique_level, dosage_level, mode_level, description_snapshot, raw_llm_response) VALUES (:pid, :round, :t, :d, :m, :tl, :dl, :ml, :snap, :raw)');
                $stmt->bindValue(':pid', $_SESSION['participant_id']);
                $stmt->bindValue(':round', $round);
                $stmt->bindValue(':t', $result['technique']['value']);
                $stmt->bindValue(':d', $result['dosage']['value']);
                $stmt->bindValue(':m', $result['mode']['value']);
                $stmt->bindValue(':tl', $result['technique']['level']);
                $stmt->bindValue(':dl', $result['dosage']['level']);
                $stmt->bindValue(':ml', $result['mode']['level']);
                $stmt->bindValue(':snap', $desc);
                $stmt->bindValue(':raw', $result['_raw'] ?? '');
                $stmt->execute();
            }

            $_SESSION['current_practice'] = $result;
            $_SESSION['c3_analyses'] = $analyses + 1;
            header('Location: ?step=refinement');
            exit;
        }
    } elseif ($action === 'continue') {
        if ($analyses < 1) {
            $error = 'Please use "Check my description" at least once before continuing.';
        } else {
            if ($desc !== '') {
                $_SESSION['description'] = $desc;
            }
            $rounds_taken = $analyses - 1; // re-analyses after the first
            if (!$is_test) {
                $db = get_db();
                $stmt = $db->prepare('UPDATE participants SET rounds_taken = :rt WHERE id = :pid');
                $stmt->bindValue(':rt', $rounds_taken);
                $stmt->bindValue(':pid', $_SESSION['participant_id']);
                $stmt->execute();
            }
            $_SESSION['rounds_taken'] = $rounds_taken;
            header('Location: ?step=fidelity');
            exit;
        }
    }
}

// Preview/fill: seed the box so the researcher can click Check straight away.
if ($fill && $desc === '') {
    $desc = $syn['description'] ?? '';
}

// Fixed, dimension-level hint strings. We deliberately do NOT use the LLM's
// per-response hint text in the UI, because LLM-generated hints drift to
// technique-specific phrasing (e.g., "how many cycles?" for breathwork vs
// "how many laps?" for running), which would make C3 participants see
// different cues than C1/C2 and threaten the cross-condition comparison.
// These strings mirror the C2 nudge clauses verbatim so every participant
// who sees a cue sees the same words. The LLM's raw hint field is still
// captured in raw_llm_response for later inspection.
$dims = [
    'technique' => ['title' => 'What exactly you do',  'states' => [0 => 'not mentioned', 1 => 'general type', 2 => 'named',            3 => 'detailed'], 'hint' => 'Try naming what exactly you do.'],
    'dosage'    => ['title' => 'How much',     'states' => [0 => 'not mentioned', 1 => 'vague',        2 => 'partly specified', 3 => 'detailed'], 'hint' => 'Try adding how much.'],
    'mode'      => ['title' => 'In what way',  'states' => [0 => 'not mentioned', 1 => 'general',      2 => 'specified',        3 => 'detailed'], 'hint' => 'Try adding in what way.'],
];

$at_cap = ($analyses >= C3_MAX_ANALYSES);

require __DIR__ . '/../templates/header.php';
?>

<div class="progress-bar-custom">
    <div class="fill" style="width: <?= $progress ?>%"></div>
</div>

<div class="spinner-overlay" id="spinner">
    <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;"></div>
    <p class="text-muted">Reading your description...</p>
</div>

<style>
    .dim-row { margin-bottom: 1rem; }
    .dim-head { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: .3rem; }
    .dim-title { font-weight: 600; }
    .dim-state { font-size: .85rem; color: #6c757d; }
    .dim-track { height: 10px; background: #e9ecef; border-radius: 5px; overflow: hidden; }
    .dim-bar { height: 100%; border-radius: 5px; transition: width .3s ease; }
    .dim-bar.l0 { width: 6%;   background: #ced4da; }
    .dim-bar.l1 { width: 38%;  background: #9ec5fe; }
    .dim-bar.l2 { width: 70%;  background: #6ea8fe; }
    .dim-bar.l3 { width: 100%; background: #3d8bfd; }
    .dim-hint { font-size: .85rem; color: #6c757d; margin-top: .3rem; }
    .reassure { font-size: .9rem; color: #6c757d; background: #f1f3f5; border-radius: 6px; padding: .6rem .8rem; margin-bottom: .9rem; }
</style>

<div class="study-card">
    <h4 class="mb-3">Your Practice</h4>
    <p class="lead">Think of something you do <strong>specifically when you are feeling stressed or anxious</strong> to help yourself feel better.</p>
    <p>Describe it in your own words. When you are ready, use <strong>Check my description</strong> to see what an AI assistant picked up, and add detail if you like.</p>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" onsubmit="document.getElementById('spinner').classList.add('active')">
        <textarea class="form-control mb-3" name="description" rows="6" placeholder="Write about your practice here..."><?= htmlspecialchars($desc) ?></textarea>

        <?php if ($practice): ?>
            <h6 class="text-muted mb-3">What the AI picked up so far</h6>
            <?php foreach ($dims as $key => $meta):
                $level = (int)($practice[$key]['level'] ?? 0);
            ?>
            <div class="dim-row">
                <div class="dim-head">
                    <span class="dim-title"><?= $meta['title'] ?></span>
                    <span class="dim-state"><?= htmlspecialchars($meta['states'][$level] ?? '') ?></span>
                </div>
                <div class="dim-track"><div class="dim-bar l<?= $level ?>"></div></div>
                <?php if ($level < 3): ?>
                    <div class="dim-hint"><?= htmlspecialchars($meta['hint']) ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <div class="reassure">
                Refine further or stop whenever this feels accurate to you. There is no right answer, and your payment does not depend on the indicators or on how many times you check.
            </div>
        <?php endif; ?>

        <div class="d-flex gap-2">
            <?php if (!$at_cap): ?>
                <button type="submit" name="action" value="analyse" class="btn btn-primary btn-lg flex-fill">
                    <?= $practice ? 'Check again' : 'Check my description' ?>
                </button>
            <?php endif; ?>
            <?php if ($analyses >= 1): ?>
                <button type="submit" name="action" value="continue" class="btn btn-success btn-lg flex-fill">This is accurate</button>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php require __DIR__ . '/../templates/footer.php'; ?>
