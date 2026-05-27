<?php
return [
    'db_path' => __DIR__ . '/data/atlas.db',
    'llm_api_key' => getenv('OPENROUTER_API_KEY') ?: 'sk-or-xxxxx',
    'llm_model' => getenv('OPENROUTER_MODEL') ?: 'anthropic/claude-sonnet-4.6',
    'admin_key' => 'change-this-secret',
    'prolific_completion_url' => 'https://app.prolific.com/submissions/complete?cc=XXXXXX',
];
