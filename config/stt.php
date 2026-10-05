<?php

return [
    // none = voice notes are declined politely. openai_compatible = any /audio/transcriptions service. fake = scripted (dev/tests; refused in production).
    'provider' => env('STT_PROVIDER', 'none'),

    'openai_compatible' => [
        'base_url' => env('STT_BASE_URL', 'https://api.openai.com/v1'),
        'api_key' => env('STT_API_KEY'),
        'model' => env('STT_MODEL', 'whisper-1'),
        'language' => env('STT_LANGUAGE') ?: null, // e.g. "hi"; unset lets the model detect it
        'timeout_seconds' => (int) env('STT_TIMEOUT_SECONDS', 30),
    ],
];
