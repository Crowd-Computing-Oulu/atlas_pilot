<?php
require __DIR__ . '/../app/openrouter_models.php';

function check(bool $cond, string $msg): void { if (!$cond) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); } }

$sample = json_encode(['data' => [
    ['id' => 'anthropic/claude-sonnet-4.6', 'name' => 'Anthropic: Claude Sonnet 4.6'],
    ['id' => 'openai/gpt-4o',               'name' => 'OpenAI: GPT-4o'],
    ['id' => 'broken-no-name'],            // missing name -> name falls back to id
    ['name' => 'no id, should be dropped'], // missing id -> dropped
]]);

$models = parse_openrouter_models($sample);
check(count($models) === 3, 'expected 3 valid models, got ' . count($models));
check($models[0]['id'] === 'anthropic/claude-sonnet-4.6', 'first id');
check($models[0]['name'] === 'Anthropic: Claude Sonnet 4.6', 'first name');
check($models[2]['id'] === 'broken-no-name', 'third id');
check($models[2]['name'] === 'broken-no-name', 'name should fall back to id');

check(parse_openrouter_models('not json') === [], 'bad json -> empty array');
check(parse_openrouter_models('{}') === [], 'no data key -> empty array');

// cache round-trip
$cfg = ['db_path' => '/tmp/atlas_cache_test/x.db'];
@mkdir('/tmp/atlas_cache_test', 0755, true);
@unlink(openrouter_models_cache_path($cfg));
write_openrouter_cache($cfg, $models);
$loaded = load_openrouter_models($cfg);
check($loaded['models'][1]['id'] === 'openai/gpt-4o', 'cache round-trip failed');
check(is_int($loaded['fetched_at']) && $loaded['fetched_at'] > 0, 'fetched_at missing');
check(load_openrouter_models(['db_path' => '/tmp/atlas_cache_test_nonexistent/none.db']) === ['fetched_at' => 0, 'models' => []], 'missing cache should be empty');

echo "Task4 openrouter parse + cache OK\n";
