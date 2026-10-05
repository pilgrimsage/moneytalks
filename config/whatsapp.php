<?php

use App\Services\WhatsApp\Handlers\InterpretationHandler;

return [
    // meta = the real WhatsApp Cloud API. telegram = Telegram Bot API. fake = no network (local development and tests); refused in production.
    'provider' => env('WHATSAPP_PROVIDER', 'meta'),

    'meta' => [
        'api_base' => env('META_API_BASE', 'https://graph.facebook.com'),
        'graph_version' => env('META_GRAPH_VERSION', 'v21.0'), // pin deliberately; verify the current version
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        'waba_id' => env('META_WABA_ID'),
        'phone_number_id' => env('META_PHONE_NUMBER_ID'),
        'access_token' => env('META_ACCESS_TOKEN'),
        'verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'),
        'timeout_seconds' => (int) env('META_TIMEOUT_SECONDS', 10),
    ],

    // Telegram Bot API (WHATSAPP_PROVIDER=telegram). The bot token is a secret: it is part of every Bot API URL, so it must
    // never be logged or sent anywhere except api_base.
    'telegram' => [
        'api_base' => env('TELEGRAM_API_BASE', 'https://api.telegram.org'),
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'), // sent back by Telegram in X-Telegram-Bot-Api-Secret-Token
        'timeout_seconds' => (int) env('TELEGRAM_TIMEOUT_SECONDS', 15),
    ],

    // Free-form messages are only allowed this long after the user's last inbound message.
    'window_hours' => (int) env('WHATSAPP_WINDOW_HOURS', 24),

    'webhook' => [
        'max_body_bytes' => (int) env('WHATSAPP_MAX_BODY_BYTES', 1_000_000),
        // Run the job right after the 200 is flushed (low latency). The cron-driven reaper is the safety net.
        'process_after_response' => (bool) env('WHATSAPP_PROCESS_AFTER_RESPONSE', true),
        'throttle_per_minute' => (int) env('WHATSAPP_WEBHOOK_THROTTLE', 600),
        // Reaper: re-drive events stuck in received/failed (or crashed in processing) for this long.
        'reap_after_seconds' => (int) env('WHATSAPP_REAP_AFTER_SECONDS', 120),
        'processing_timeout_seconds' => (int) env('WHATSAPP_PROCESSING_TIMEOUT', 600),
        'max_attempts' => (int) env('WHATSAPP_MAX_ATTEMPTS', 5),
    ],

    'outbound' => [
        'max_text_length' => 4000,        // WhatsApp's hard limit is 4096
        'send_attempts' => 3,
        'retry_backoff_ms' => [300, 1500],
    ],

    // Voice notes and receipt photos: checked before anything is downloaded or sent to a vendor.
    'media' => [
        'audio_mimes' => ['audio/ogg', 'audio/opus', 'audio/mpeg', 'audio/mp4', 'audio/aac', 'audio/amr', 'audio/webm'],
        'image_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
        'max_audio_bytes' => (int) env('WHATSAPP_MAX_AUDIO_BYTES', 5_000_000),
        'max_image_bytes' => (int) env('WHATSAPP_MAX_IMAGE_BYTES', 5_000_000),
        // Meta's media download URLs must be on one of these domains (plus the Graph API host); the bearer token is never sent elsewhere.
        'allowed_host_suffixes' => ['.fbcdn.net', '.facebook.com', '.fbsbx.com', '.whatsapp.net', '.fb.com'],
        'per_user_daily' => (int) env('WHATSAPP_MEDIA_PER_USER_DAILY', 30),
    ],

    'rate_limit' => [
        'user_messages_per_minute' => (int) env('WHATSAPP_USER_MSGS_PER_MIN', 30),
    ],

    // Class that decides what to do with an inbound message (swapped in M5).
    'handler' => env('WHATSAPP_HANDLER', InterpretationHandler::class),
];
