<?php

function call_claude(string $system_prompt, string $user_message): ?array {
    $config = require __DIR__ . '/config.php';

    $payload = json_encode([
        'model' => $config['claude_model'],
        'max_tokens' => 1024,
        'system' => $system_prompt,
        'messages' => [
            ['role' => 'user', 'content' => $user_message]
        ]
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $config['claude_api_key'],
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($http_code !== 200 || $response === false) {
        return null;
    }

    $data = json_decode($response, true);
    $text = $data['content'][0]['text'] ?? null;
    if (!$text) return null;

    return ['text' => $text, 'raw' => $response];
}

function extract_gene(string $description): ?array {
    $system = "You are a research assistant helping to encode self-care practices into structured triples of three primitives: Technique, Dosage, and Mode.

Given a free-text description of a practice the person uses specifically when feeling stressed or anxious, extract three primitive values:
- Technique: What exactly the person does
- Dosage: How much, how long, how often
- Mode: In what form, setting, or with what support

For each dimension, provide the extracted value and a confidence level (high, medium, low).
If a dimension is not mentioned or unclear, set value to null and confidence to low.

Respond ONLY with valid JSON in this exact format:
{
  \"technique\": {\"value\": \"...\", \"confidence\": \"high|medium|low\"},
  \"dosage\": {\"value\": \"...\", \"confidence\": \"high|medium|low\"},
  \"mode\": {\"value\": \"...\", \"confidence\": \"high|medium|low\"}
}";

    $result = call_claude($system, "Description: " . $description);
    if (!$result) return null;

    $json_match = [];
    if (preg_match('/\{[\s\S]*\}/', $result['text'], $json_match)) {
        $parsed = json_decode($json_match[0], true);
        if ($parsed) {
            $parsed['_raw'] = $result['raw'];
            return $parsed;
        }
    }

    return null;
}

function refine_gene(string $original_description, array $current_gene, string $participant_response, string $targeted_dimension): ?array {
    $system = "You are a research assistant helping to refine a structured practice (Technique, Dosage, Mode primitives) describing a participant's self-care.

The participant originally described a practice they use specifically when feeling stressed or anxious. An initial extraction was made, and now the participant has provided additional detail about the {$targeted_dimension} dimension.

Update the extraction with the new information. Then write a follow-up question targeting the NEXT weakest dimension (any dimension other than the one just refined). The follow-up question is shown verbatim to a research participant in a formal academic study: use complete sentences, formal register, no contractions, and no em-dashes.

Respond ONLY with valid JSON:
{
  \"technique\": {\"value\": \"...\", \"confidence\": \"high|medium|low\"},
  \"dosage\": {\"value\": \"...\", \"confidence\": \"high|medium|low\"},
  \"mode\": {\"value\": \"...\", \"confidence\": \"high|medium|low\"},
  \"next_question\": \"...\",
  \"next_target\": \"technique|dosage|mode\"
}";

    $gene_json = json_encode($current_gene, JSON_PRETTY_PRINT);
    $user_msg = "Original description: {$original_description}\n\nCurrent gene extraction:\n{$gene_json}\n\nParticipant's additional detail about {$targeted_dimension}:\n{$participant_response}";

    $result = call_claude($system, $user_msg);
    if (!$result) return null;

    $json_match = [];
    if (preg_match('/\{[\s\S]*\}/', $result['text'], $json_match)) {
        $parsed = json_decode($json_match[0], true);
        if ($parsed) {
            $parsed['_raw'] = $result['raw'];
            return $parsed;
        }
    }

    return null;
}

function get_initial_question(array $gene): array {
    $dimensions = ['technique', 'dosage', 'mode'];
    $confidence_order = ['low' => 0, 'medium' => 1, 'high' => 2];

    $weakest = 'mode';
    $weakest_score = 3;
    foreach ($dimensions as $dim) {
        $conf = $gene[$dim]['confidence'] ?? 'low';
        $score = $confidence_order[$conf] ?? 0;
        if ($score < $weakest_score) {
            $weakest_score = $score;
            $weakest = $dim;
        }
    }

    $questions = [
        'technique' => "Could you describe in more detail what you do during this practice? Please include the specific steps or method.",
        'dosage' => "Could you describe how much you do this practice? In particular, how long does a typical session last, and how often do you do it?",
        'mode' => "Could you describe how you carry out this practice? For example, do you do it alone or with others, in a specific place or setting, and with any tools or applications?",
    ];

    return [
        'dimension' => $weakest,
        'question' => $questions[$weakest],
    ];
}
