<?php
$page_title = 'ATLAS Study — Refining Your Practice';
require_once __DIR__ . '/../llm.php';

if (!isset($_SESSION['participant_id']) || ($_SESSION['condition'] ?? 0) !== 3) {
    header('Location: ?step=consent');
    exit;
}

if (!isset($_SESSION['description'])) {
    header('Location: ?step=input');
    exit;
}

// First entry: do the round-0 extraction and start at the gate.
if (!isset($_SESSION['refinement_round'])) {
    $practice = extract_gene($_SESSION['description']);
    if (!$practice) {
        $practice = [
            'technique' => ['value' => null, 'confidence' => 'low'],
            'dosage' => ['value' => null, 'confidence' => 'low'],
            'mode' => ['value' => null, 'confidence' => 'low'],
        ];
    }

    if (!$is_test) {
        $db = get_db();
        $stmt = $db->prepare('INSERT INTO gene_extractions (participant_id, round, technique, dosage, mode, technique_confidence, dosage_confidence, mode_confidence, raw_llm_response) VALUES (:pid, 0, :t, :d, :m, :tc, :dc, :mc, :raw)');
        $stmt->bindValue(':pid', $_SESSION['participant_id']);
        $stmt->bindValue(':t', $practice['technique']['value']);
        $stmt->bindValue(':d', $practice['dosage']['value']);
        $stmt->bindValue(':m', $practice['mode']['value']);
        $stmt->bindValue(':tc', $practice['technique']['confidence']);
        $stmt->bindValue(':dc', $practice['dosage']['confidence']);
        $stmt->bindValue(':mc', $practice['mode']['confidence']);
        $stmt->bindValue(':raw', $practice['_raw'] ?? '');
        $stmt->execute();
    }

    $_SESSION['current_practice'] = $practice;
    $_SESSION['refinement_round'] = 0;       // refinement rounds completed so far
    $_SESSION['refinement_phase'] = 'gate';  // 'gate' or 'ask'
}

$round = $_SESSION['refinement_round'];
$phase = $_SESSION['refinement_phase'];

// POST handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($phase === 'gate') {
        $decision = $_POST['gate_decision'] ?? '';
        $is_final = ($round >= 2);

        if (!$is_test) {
            $db = get_db();
            $stmt = $db->prepare('UPDATE gene_extractions SET gate_decision = :gd WHERE participant_id = :pid AND round = :round');
            $stmt->bindValue(':gd', $decision);
            $stmt->bindValue(':pid', $_SESSION['participant_id']);
            $stmt->bindValue(':round', $round);
            $stmt->execute();
        }

        if ($decision === 'yes' || $is_final) {
            $rounds_taken = ($decision === 'yes') ? $round : 2;
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

        if ($decision === 'no') {
            // Set up the follow-up question targeting the weakest dimension.
            if (!isset($_SESSION['current_question']) || !isset($_SESSION['current_target']) || $round === 0) {
                $initial = get_initial_question($_SESSION['current_practice']);
                $_SESSION['current_target'] = $initial['dimension'];
                $_SESSION['current_question'] = $initial['question'];
            }
            $_SESSION['refinement_phase'] = 'ask';
            header('Location: ?step=refinement');
            exit;
        }
    } elseif ($phase === 'ask') {
        $response = trim($_POST['response'] ?? '');
        if (strlen($response) < 5) {
            $error = 'Please provide a bit more detail.';
        } else {
            $target = $_SESSION['current_target'];

            if (!$is_test) {
                $db = get_db();
                $stmt = $db->prepare('INSERT INTO responses (participant_id, step, prompt_shown, response_text) VALUES (:pid, :step, :prompt, :text)');
                $stmt->bindValue(':pid', $_SESSION['participant_id']);
                $stmt->bindValue(':step', 'refinement_r' . ($round + 1));
                $stmt->bindValue(':prompt', $_SESSION['current_question']);
                $stmt->bindValue(':text', $response);
                $stmt->execute();
            }

            $refined = refine_gene(
                $_SESSION['description'],
                $_SESSION['current_practice'],
                $response,
                $target
            );

            $next_target = 'mode';
            $next_question = 'Could you describe in more detail how you carry out this practice?';

            if ($refined) {
                $_SESSION['current_practice'] = $refined;
                $next_target = $refined['next_target'] ?? 'mode';
                $next_question = $refined['next_question'] ?? $next_question;
            } else {
                $refined = $_SESSION['current_practice'];
            }

            $new_round = $round + 1;

            if (!$is_test) {
                $db = get_db();
                $stmt = $db->prepare('INSERT INTO gene_extractions (participant_id, round, technique, dosage, mode, technique_confidence, dosage_confidence, mode_confidence, targeted_dimension, ai_question, raw_llm_response) VALUES (:pid, :round, :t, :d, :m, :tc, :dc, :mc, :td, :aq, :raw)');
                $stmt->bindValue(':pid', $_SESSION['participant_id']);
                $stmt->bindValue(':round', $new_round);
                $stmt->bindValue(':t', $refined['technique']['value'] ?? null);
                $stmt->bindValue(':d', $refined['dosage']['value'] ?? null);
                $stmt->bindValue(':m', $refined['mode']['value'] ?? null);
                $stmt->bindValue(':tc', $refined['technique']['confidence'] ?? 'low');
                $stmt->bindValue(':dc', $refined['dosage']['confidence'] ?? 'low');
                $stmt->bindValue(':mc', $refined['mode']['confidence'] ?? 'low');
                $stmt->bindValue(':td', $target);
                $stmt->bindValue(':aq', $_SESSION['current_question']);
                $stmt->bindValue(':raw', $refined['_raw'] ?? '');
                $stmt->execute();
            }

            // Set up the next round's question (used if participant clicks "No" again at the next gate).
            $_SESSION['current_target'] = $next_target;
            $_SESSION['current_question'] = $next_question;
            $_SESSION['refinement_round'] = $new_round;
            $_SESSION['refinement_phase'] = 'gate';
            header('Location: ?step=refinement');
            exit;
        }
    }
}

$practice = $_SESSION['current_practice'] ?? [];
$round = $_SESSION['refinement_round'];
$phase = $_SESSION['refinement_phase'];
$is_final = ($round >= 2);

require __DIR__ . '/../templates/header.php';
?>

<div class="progress-bar-custom">
    <div class="fill" style="width: <?= $progress ?>%"></div>
</div>

<div class="spinner-overlay" id="spinner">
    <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;"></div>
    <p class="text-muted">Analysing your response...</p>
</div>

<?php if ($phase === 'gate'): ?>

<div class="study-card">
    <?php if ($is_final): ?>
        <h4 class="mb-3">Here's your refined practice</h4>
        <p class="text-muted">After two rounds of refinement, here is the final structured summary of what you described.</p>
    <?php elseif ($round === 0): ?>
        <h4 class="mb-3">How does this look?</h4>
        <p class="text-muted">Based on what you wrote, here is how we have structured your practice.</p>
    <?php else: ?>
        <h4 class="mb-3">Updated based on what you added</h4>
        <p class="text-muted">Here is the refined version after your last response.</p>
    <?php endif; ?>

    <div class="mb-4">
        <?php
        $dims = ['technique' => 'What you do', 'dosage' => 'How much / how often', 'mode' => 'In what way / setting'];
        foreach ($dims as $key => $label):
            $value = $practice[$key]['value'] ?? null;
        ?>
        <div class="gene-card">
            <div class="gene-label"><?= $label ?></div>
            <div class="gene-value">
                <?php if ($value): ?>
                    <?= htmlspecialchars($value) ?>
                <?php else: ?>
                    <span class="gene-missing">Not clearly captured yet</span>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <form method="post" onsubmit="document.getElementById('spinner').classList.add('active')">
        <?php if ($is_final): ?>
            <button type="submit" name="gate_decision" value="continue" class="btn btn-primary btn-lg w-100">Continue</button>
        <?php else: ?>
            <p class="fw-bold">Does this accurately summarise the practice you described?</p>
            <div class="d-flex gap-2">
                <button type="submit" name="gate_decision" value="yes" class="btn btn-success btn-lg flex-fill">Yes, accurate</button>
                <button type="submit" name="gate_decision" value="no" class="btn btn-outline-primary btn-lg flex-fill">No, let me refine</button>
            </div>
        <?php endif; ?>
    </form>
</div>

<?php elseif ($phase === 'ask'): ?>

<div class="study-card">
    <h4 class="mb-3">Tell us a bit more</h4>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="alert alert-info">
        <?= htmlspecialchars($_SESSION['current_question']) ?>
    </div>

    <form method="post" onsubmit="document.getElementById('spinner').classList.add('active')">
        <textarea class="form-control mb-3" name="response" rows="3" placeholder="Your response..."><?= htmlspecialchars($_POST['response'] ?? '') ?></textarea>
        <button type="submit" class="btn btn-primary btn-lg w-100">Continue</button>
    </form>
</div>

<?php endif; ?>

<?php require __DIR__ . '/../templates/footer.php'; ?>
