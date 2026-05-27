<?php

function call_claude(string $system_prompt, string $user_message): ?array {
    $config = require __DIR__ . '/config.php';

    $payload = json_encode([
        'model' => $config['llm_model'],
        'max_tokens' => 1024,
        'messages' => [
            ['role' => 'system', 'content' => $system_prompt],
            ['role' => 'user', 'content' => $user_message],
        ],
    ]);

    // OpenRouter (OpenAI-compatible chat completions endpoint).
    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $config['llm_api_key'],
            'X-Title: ATLAS Pilot',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($http_code !== 200 || $response === false) {
        return null;
    }

    $data = json_decode($response, true);
    $text = $data['choices'][0]['message']['content'] ?? null;
    if (!$text) return null;

    return ['text' => $text, 'raw' => $response];
}

/**
 * Score a free-text practice description on the 0-3 specificity rubric for each
 * dimension (Technique, Dosage, Mode). The model only assesses the text; it never
 * rewrites it. Returns per-dimension {value, level (0-3), hint} plus _raw, or null
 * if the call/parse fails.
 */
function analyse_practice(string $description): ?array {
    $system = "You analyse a free-text description of a self-care practice that a person uses specifically when feeling stressed or anxious. You score how specifically the text encodes three dimensions, using the rubric below. You do NOT rewrite, improve, or add to the person's text. You only assess what is already there, and where a dimension is below the top level you offer one short, optional suggestion of what kind of detail could be added.

Dimensions and 0-3 specificity levels:

TECHNIQUE (what the person does):
 0 absent: no technique mentioned
 1 category: broad category only (e.g. 'relaxation', 'exercise')
 2 named: a specific named practice (e.g. 'box breathing', 'running')
 3 parameterised: a named practice with defining parameters (e.g. '4-4-4-4 box breathing')

DOSAGE (how much / how often):
 0 absent: no dosage information
 1 vague: non-quantified (e.g. 'sometimes', 'when I need it')
 2 single parameter: one of duration, frequency, or intensity (e.g. '20 minutes' or '3x per week')
 3 multi parameter: two or more (e.g. '20 min, 3x per week')

MODE (in what form / setting):
 0 absent: no mode information
 1 vague: minimal context (e.g. 'by myself', 'at home')
 2 specified: a clear mode with one qualifier (e.g. 'solo outdoors')
 3 operationalised: mode plus a delivery mechanism or setting detail (e.g. 'solo outdoors using a meditation app, in a park')

For each dimension return: value (a short phrase capturing what was said, or null if absent), level (integer 0-3 from the rubric), and hint (a short, friendly, OPTIONAL suggestion of what detail could be added; use an empty string when level is 3). Keep hints gentle and never imply the person did something wrong.

Respond ONLY with valid JSON in exactly this format:
{
  \"technique\": {\"value\": \"...\", \"level\": 0, \"hint\": \"...\"},
  \"dosage\": {\"value\": \"...\", \"level\": 0, \"hint\": \"...\"},
  \"mode\": {\"value\": \"...\", \"level\": 0, \"hint\": \"...\"}
}";

    $result = call_claude($system, "Description: " . $description);
    if (!$result) return null;

    $json_match = [];
    if (preg_match('/\{[\s\S]*\}/', $result['text'], $json_match)) {
        $parsed = json_decode($json_match[0], true);
        if ($parsed && isset($parsed['technique'], $parsed['dosage'], $parsed['mode'])) {
            foreach (['technique', 'dosage', 'mode'] as $d) {
                $parsed[$d]['level'] = max(0, min(3, (int)($parsed[$d]['level'] ?? 0)));
                $parsed[$d]['value'] = ($parsed[$d]['value'] ?? null) ?: null;
                $parsed[$d]['hint'] = (string)($parsed[$d]['hint'] ?? '');
            }
            $parsed['_raw'] = $result['raw'];
            return $parsed;
        }
    }
    return null;
}
