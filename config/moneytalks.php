<?php

use App\Support\AllowList;

return [
    // Bearer token for GET /health (an uptime monitor). Unset = the endpoint answers 404.
    'health_token' => env('HEALTH_TOKEN'),

    'defaults' => [
        'timezone' => env('DEFAULT_TIMEZONE', 'Asia/Kolkata'),
        'currency' => env('DEFAULT_CURRENCY', 'INR'),
        'locale' => env('DEFAULT_LOCALE', 'en-IN'),
    ],

    // Personal mode: only these ids may use the bot (digits only). The list depends on the active channel (WHATSAPP_PROVIDER):
    // telegram -> ALLOWED_TELEGRAM_IDS (falls back to ALLOWED_WA_IDS), anything else -> ALLOWED_WA_IDS (country code, no '+').
    'allowed_wa_ids' => AllowList::forProvider(env('WHATSAPP_PROVIDER'), env('ALLOWED_TELEGRAM_IDS'), env('ALLOWED_WA_IDS')),

    // HMAC key for the wa_id blind index. Must be set outside testing; rotate with a re-index.
    'blind_index_key' => env('PII_BLIND_INDEX_KEY'),

    'conversation' => [
        'ttl_minutes' => (int) env('CONVERSATION_TTL_MINUTES', 10),
        'max_clarify_turns' => 3,
        'duplicate_window_seconds' => (int) env('DUPLICATE_WINDOW_SECONDS', 120),
    ],

    // Entity resolution thresholds (docs/ai.md, "Entity resolution").
    'resolver' => [
        'fuzzy_min_similarity' => 0.80,
        'fuzzy_min_length' => 4,
        'ambiguity_margin' => 0.05,
    ],
];
