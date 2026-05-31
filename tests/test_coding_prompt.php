<?php

function check(bool $cond, string $msg): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); }
}

// $expected is the verbatim content of the $system string in analyse_practice()
// as it existed before the refactor. Copied byte-for-byte from app/llm.php.
$expected = <<<'TXT'
You analyse a free-text description of a self-care practice that a person uses specifically when feeling stressed or anxious. You score how specifically the text encodes three dimensions, using the rubric below. You do NOT rewrite, improve, or add to the person's text. You only assess what is already there, and where a dimension is below the top level you offer one short, optional suggestion of what kind of detail could be added.

Dimensions and 0-3 specificity levels:

TECHNIQUE (what the person does):
 0 absent: no technique mentioned
 1 category: broad category only (e.g. 'relaxation', 'exercise')
 2 named: a specific named practice (e.g. 'box breathing', 'running')
 3 parameterised: a named practice with defining parameters (e.g. '4-4-4-4 box breathing')

DOSAGE (the magnitude or extent of the practice; technique-conditional, so any quantitative anchor counts equally regardless of which sub-dimension it falls on):
 0 absent: no information about magnitude or extent
 1 vague: non-quantified (e.g. 'sometimes', 'when I need it', 'a bit')
 2 single parameter: any one quantitative anchor of any type, with no preference between sub-dimensions (e.g. '20 minutes', '5 cycles', '3x per week', 'until I feel calmer', '2 km', 'deep slow breaths')
 3 multi parameter: two or more quantitative anchors of any type (e.g. '20 min, 3x per week' or '5 cycles with a 4:4 ratio')

MODE (how the practice is enacted; technique-conditional, so any clear mode descriptor counts equally regardless of which sub-axis it falls on. MODE does NOT include the situation or trigger in which the practice is used, that is Context and is scored separately):
 0 absent: no information about how the practice is enacted
 1 vague: minimal detail (e.g. 'by myself', 'with help')
 2 specified: a clear mode descriptor of any kind (e.g. 'Solo', 'in a group', 'with an app', 'online', 'unguided')
 3 operationalised: mode plus a specific delivery mechanism (e.g. 'Solo using a meditation app for guidance')

If the text describes more than one distinct practice (for example breathing AND a walk), score the PRIMARY practice only: the one the writer leads with or describes in the most detail. Dosage and Mode then refer to that primary practice. Separately report technique_count, the number of distinct practices described (1, 2, or 3 for three or more).

For each dimension return: value (a short phrase capturing what was said, or null if absent), level (integer 0-3 from the rubric), and hint (a short, friendly, OPTIONAL suggestion of what detail could be added; use an empty string when level is 3). Keep hints gentle and never imply the person did something wrong.

Respond ONLY with valid JSON in exactly this format:
{
  "technique": {"value": "...", "level": 0, "hint": "..."},
  "dosage": {"value": "...", "level": 0, "hint": "..."},
  "mode": {"value": "...", "level": 0, "hint": "..."},
  "technique_count": 1
}
TXT;

require __DIR__ . '/../app/llm.php';

check(function_exists('default_coding_system_prompt'), 'default_coding_system_prompt() missing');

$got = default_coding_system_prompt();

if ($got !== $expected) {
    $len = min(strlen($got), strlen($expected));
    $first_diff = $len; // default: lengths differ
    for ($i = 0; $i < $len; $i++) {
        if ($got[$i] !== $expected[$i]) {
            $first_diff = $i;
            break;
        }
    }
    $start = max(0, $first_diff - 40);
    $got_window    = substr($got,      $start, 80);
    $expected_window = substr($expected, $start, 80);
    fwrite(STDERR, "First differing byte offset: $first_diff\n");
    fwrite(STDERR, "strlen(got)=" . strlen($got) . " strlen(expected)=" . strlen($expected) . "\n");
    fwrite(STDERR, "got     [offset $start]: " . json_encode($got_window)      . "\n");
    fwrite(STDERR, "expected[offset $start]: " . json_encode($expected_window) . "\n");
}

check($got === $expected, 'default prompt does not match the original byte-for-byte');

// Task 3: signatures gained system_prompt + temperature with safe defaults.
$ref = new ReflectionFunction('analyse_practice');
$params = array_map(fn($p) => $p->getName(), $ref->getParameters());
check($params === ['description','model','system_prompt','temperature'], 'analyse_practice signature: ' . implode(',', $params));
foreach ([1,2,3] as $i) check($ref->getParameters()[$i]->isOptional(), "analyse_practice param $i must be optional");

$cref = new ReflectionFunction('call_claude');
$cparams = array_map(fn($p) => $p->getName(), $cref->getParameters());
check($cparams === ['system_prompt','user_message','model','temperature'], 'call_claude signature: ' . implode(',', $cparams));

echo "Task2 prompt OK\n";
