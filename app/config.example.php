<?php
return [
    'db_path' => __DIR__ . '/data/atlas.db',
    'llm_api_key' => getenv('OPENROUTER_API_KEY') ?: 'sk-or-xxxxx',
    'llm_model' => getenv('OPENROUTER_MODEL') ?: 'anthropic/claude-sonnet-4.6',
    'admin_key' => 'change-this-secret',
    'prolific_completion_url' => 'https://app.prolific.com/submissions/complete?cc=XXXXXX',

    // Post-hoc specificity coding facility (optional; all have safe code defaults).
    // Completion URL for crowd raters returning from a Taskflow coding study.
    'coding_completion_url' => 'https://app.prolific.com/submissions/complete?cc=XXXXXX',
    // Absolute base URL used to build Taskflow task URLs; derived from the request host if unset.
    'app_base_url' => 'https://atlas-web-production-4c95.up.railway.app',
    // Models for the multi-model LLM coding pass (OpenRouter slugs). Defaults to [llm_model].
    'coding_models' => ['anthropic/claude-sonnet-4.6'],
];
