<?php

return [
    // anthropic = real API. fake = scripted, no network (local dev and tests); refused in production.
    'provider' => env('AI_PRIMARY_PROVIDER', 'anthropic'),

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'timeout_seconds' => max(1, (int) ceil(((int) env('AI_REQUEST_TIMEOUT_MS', 20000)) / 1000)),
        'max_retries' => 1, // the SDK's own retries; the gateway adds one more attempt plus fallback
    ],

    // Model IDs are configuration, never constants in code (docs/ai.md). Verify current IDs/prices before changing.
    'models' => [
        'fast' => env('AI_MODEL_FAST', 'claude-haiku-4-5'),
        'strong' => env('AI_MODEL_STRONG') ?: null, // e.g. claude-sonnet-5-5; unused unless escalation is on
    ],
    'escalation_enabled' => (bool) env('AI_ESCALATION_ENABLED', false),

    // Omit (null) for models that reject sampling parameters.
    'temperature' => env('AI_TEMPERATURE') === null ? 0.0 : (env('AI_TEMPERATURE') === 'null' ? null : (float) env('AI_TEMPERATURE')),
    'retry_backoff_ms' => (int) env('AI_RETRY_BACKOFF_MS', 400),
    'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 1024),

    'retention_days' => (int) env('AI_RETENTION_DAYS', 30), // prompt/response bodies; token/cost metadata is kept

    'limits' => [
        'user_requests_per_day' => (int) env('AI_USER_REQUESTS_PER_DAY', 300),
        'max_items_per_message' => 5,
        // All AI spend today (micro-USD) above which AI work is refused until tomorrow. 0 = no limit. AI_DAILY_BUDGET_GLOBAL_USD=2 means two dollars.
        'global_daily_budget_micros' => (int) round(((float) env('AI_DAILY_BUDGET_GLOBAL_USD', 0)) * 1_000_000),
    ],

    // What the application is willing to do on its own (docs/ai.md section 9; all configurable).
    'risk' => [
        'auto_commit_min_score' => (float) env('AI_AUTO_COMMIT_MIN_SCORE', 0.80),
        // Between this and auto_commit_min_score the user is asked to Confirm; below it the user is asked to rephrase.
        'confirm_min_score' => (float) env('AI_CONFIRM_MIN_SCORE', 0.55),
        // Expenses/transfers above this always need a Confirm tap.
        'confirm_above_minor' => (int) env('AI_CONFIRM_ABOVE_MINOR', 10_000_000), // INR 1,00,000
        'max_amount_minor' => (int) env('AI_MAX_AMOUNT_MINOR', 1_000_000_000),    // INR 1,00,00,000
        'max_past_days' => 400,
        'max_future_days' => 1, // timezone slack only; nobody has spent money tomorrow
    ],
];
