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

    // Specificity coding facility. All optional; the app has safe fallbacks.
    // CODING_COMPLETION_URL: Taskflow completion redirect for crowd raters (falls back to the main study URL).
    'coding_completion_url' => getenv('CODING_COMPLETION_URL') ?: (getenv('PROLIFIC_COMPLETION_URL') ?: ''),
    // APP_BASE_URL: absolute base for Taskflow task URLs (falls back to the request host if blank).
    'app_base_url' => getenv('APP_BASE_URL') ?: '',
    // CODING_MODELS: comma-separated OpenRouter slugs for the optional LLM coding pass (defaults to llm_model).
    'coding_models' => array_values(array_filter(array_map('trim', explode(',', getenv('CODING_MODELS') ?: '')))),
];
