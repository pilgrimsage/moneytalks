<?php

use App\Jobs\ReapWebhookEvents;
use App\Services\Ops\HealthCheck;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('moneytalks:ledger:verify')->dailyAt('02:30')->withoutOverlapping();

Schedule::call(new ReapWebhookEvents)->name('whatsapp-reaper')->everyMinute()->withoutOverlapping();

Schedule::command('moneytalks:ai:purge')->dailyAt('03:10')->withoutOverlapping();

Schedule::command('moneytalks:conversation:purge')->hourly()->withoutOverlapping();

// Recurring payments: open due occurrences and remind (hourly is plenty; every send is keyed, so reruns are harmless).
Schedule::command('moneytalks:recurring:run')->hourly()->withoutOverlapping();

// Monthly closing summary: first week of the month, once per user (keyed), retried hourly if the 24h window was closed.
Schedule::command('moneytalks:monthly:close')->hourly()->withoutOverlapping();

// Heartbeat for moneytalks:health: proves the cron job is really running the scheduler.
Schedule::call(fn () => Cache::put(HealthCheck::HEARTBEAT_KEY, now()->timestamp, now()->addDay()))->name('heartbeat')->everyMinute();

// Encrypted database backup (skipped with a warning until BACKUP_ENCRYPTION_KEY is set).
Schedule::command('moneytalks:backup')->dailyAt('03:40')->withoutOverlapping();

// Retention: raw webhook payloads after 14 days, message bodies after 90, leftover export files after 24 hours.
Schedule::command('moneytalks:retention:purge')->dailyAt('03:20')->withoutOverlapping();
