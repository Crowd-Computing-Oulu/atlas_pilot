<?php
// Container config for Railway. All values come from environment variables.
// No secrets here; set them in the Railway dashboard / `railway variables`.
// The local app/config.php (gitignored) is used for local dev and is never shipped.
return [
    'db_path' => getenv('DB_PATH') ?: '/data/atlas.db',
    'llm_api_key' => getenv('OPENROUTER_API_KEY') ?: '',
    'llm_model' => getenv('OPENROUTER_MODEL') ?: 'anthropic/claude-sonnet-4.6',
    'admin_key' => getenv('ADMIN_KEY') ?: '',
    'prolific_completion_url' => getenv('PROLIFIC_COMPLETION_URL') ?: '',
];
