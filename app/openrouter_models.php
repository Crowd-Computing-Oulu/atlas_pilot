<?php

function openrouter_models_cache_path(array $config): string {
    return dirname($config['db_path']) . '/openrouter_models.json';
}

function parse_openrouter_models(string $json): array {
    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['data']) || !is_array($data['data'])) {
        return [];
    }
    $out = [];
    foreach ($data['data'] as $m) {
        if (!isset($m['id']) || $m['id'] === '') continue;
        $out[] = ['id' => (string)$m['id'], 'name' => (string)($m['name'] ?? $m['id'])];
    }
    return $out;
}

function write_openrouter_cache(array $config, array $models): void {
    $path = openrouter_models_cache_path($config);
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, json_encode(['fetched_at' => time(), 'models' => $models]));
}

function load_openrouter_models(array $config): array {
    $path = openrouter_models_cache_path($config);
    if (!is_file($path)) return ['fetched_at' => 0, 'models' => []];
    $data = json_decode((string)file_get_contents($path), true);
    if (!is_array($data) || !isset($data['models'])) return ['fetched_at' => 0, 'models' => []];
    return ['fetched_at' => (int)($data['fetched_at'] ?? 0), 'models' => $data['models']];
}

// Network fetch; returns the parsed model list or null on failure. Callers persist via write_openrouter_cache.
function fetch_openrouter_models(array $config): ?array {
    $ch = curl_init('https://openrouter.ai/api/v1/models');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . ($config['llm_api_key'] ?? ''), 'X-Title: ATLAS Pilot'],
        CURLOPT_TIMEOUT => 20,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || $resp === false) return null;
    return parse_openrouter_models($resp);
}
