<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

// No session, no CSRF: these endpoints authenticate with Meta's verify token / HMAC signature.
Route::middleware('throttle:whatsapp-webhook')->group(function () {
    Route::get('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify']);
    Route::post('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'receive']);
    // Telegram: same controller; the provider decides how the request is authenticated (secret-token header).
    Route::post('/webhooks/telegram', [WhatsAppWebhookController::class, 'receive']);
});

Route::get('/health', HealthController::class)->middleware('throttle:60,1');
