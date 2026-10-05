<?php

namespace App\Services\Ops;

use App\Services\Backup\BackupService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * What an operator needs to know in one place. Each check returns ok/not-ok plus a short detail (counts and ages only,
 * never content). `critical` checks fail the health endpoint; the rest are warnings.
 */
class HealthCheck
{
    public const HEARTBEAT_KEY = 'ops.scheduler.heartbeat';

    public const LEDGER_KEY = 'ops.ledger.verify';

    /**
     * Cache values are stored as unix timestamps: Laravel's cache refuses to restore objects (serializable_classes=false),
     * so a stored Carbon comes back broken from the database store. Also accepts a date object or string.
     */
    public static function asTime(mixed $value): CarbonImmutable
    {
        return is_int($value) || is_float($value) || (is_string($value) && ctype_digit($value))
            ? CarbonImmutable::createFromTimestamp((int) $value)
            : CarbonImmutable::parse($value);
    }

    public function __construct(private readonly AiSwitch $ai) {}

    /** @return list<array{name: string, ok: bool, critical: bool, detail: string}> */
    public function run(): array
    {
        return [
            $this->check('database', true, fn () => DB::select('select 1') ? [true, 'reachable'] : [false, 'no answer']),
            $this->check('scheduler', true, function () {
                $beat = Cache::get(self::HEARTBEAT_KEY);
                $age = $beat ? (int) abs(now()->diffInMinutes(self::asTime($beat))) : null;

                return $age === null ? [false, 'no heartbeat yet (is the cron job running?)'] : [$age <= 5, "last beat {$age} min ago"];
            }),
            $this->check('queue', true, function () {
                $pending = DB::table('jobs')->count();
                $oldest = DB::table('jobs')->min('available_at');
                $age = $oldest ? (int) round((time() - (int) $oldest) / 60) : 0;

                return [$age <= 10 && $pending <= 500, "{$pending} waiting, oldest {$age} min"];
            }),
            $this->check('failed jobs', false, function () {
                $n = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();

                return [$n === 0, "{$n} in the last 24 h"];
            }),
            $this->check('webhook events', true, function () {
                $cut = now()->subSeconds((int) config('whatsapp.webhook.reap_after_seconds') * 5);
                $stuck = DB::table('webhook_events')->whereIn('status', ['received', 'failed'])->where('received_at', '<', $cut)->count();

                return [$stuck === 0, "{$stuck} stuck"];
            }),
            $this->check('ledger verification', true, function () {
                $last = Cache::get(self::LEDGER_KEY);
                if (! $last) {
                    return [false, 'never run (it runs nightly)'];
                }
                $age = (int) abs(now()->diffInHours(self::asTime($last['at'])));

                return [$last['ok'] && $age <= 36, ($last['ok'] ? 'OK' : 'FAILED').", {$age} h ago"];
            }),
            $this->check('ledger triggers', false, function () {
                $have = DB::table('information_schema.triggers')->where('trigger_schema', DB::raw('DATABASE()'))->whereIn('trigger_name', ['ledger_entries_no_update', 'ledger_entries_no_delete', 'ledger_tx_no_delete', 'ledger_tx_limited_update'])->count();

                return [$have === 4, $have === 4 ? 'all installed' : "{$have} of 4 installed (host without TRIGGER privilege? the app guards and nightly verification still apply)"];
            }),
            $this->check('backups', false, function () {
                if ((string) config('backup.encryption_key') === '') {
                    return [false, 'not configured (BACKUP_ENCRYPTION_KEY)'];
                }
                $last = Cache::get(BackupService::LAST_KEY);
                $age = $last ? (int) abs(now()->diffInHours(self::asTime($last))) : null;

                return $age === null ? [false, 'no backup yet'] : [$age <= 36, "last backup {$age} h ago"];
            }),
            $this->check('AI', false, function () {
                $since = now()->subHour();
                $total = DB::table('ai_requests')->where('created_at', '>=', $since)->count();
                $bad = DB::table('ai_requests')->where('created_at', '>=', $since)->where('status', '!=', 'ok')->count();
                $paused = $this->ai->pausedBy();
                $over = $this->ai->overBudget();

                return [
                    ! $paused && ! $over && ! ($total >= 5 && $bad * 2 > $total),
                    $paused ? "PAUSED ({$paused['reason']})" : ($over ? 'daily budget reached' : "{$bad} of {$total} requests failed in the last hour"),
                ];
            }),
            $this->check('outbound messages', false, function () {
                $failed = DB::table('whatsapp_messages')->where('direction', 'out')->where('status', 'failed')->where('created_at', '>=', now()->subDay())->count();

                return [$failed === 0, "{$failed} failed in the last 24 h"];
            }),
        ];
    }

    /** Healthy = every critical check passes. */
    public function healthy(?array $checks = null): bool
    {
        foreach ($checks ?? $this->run() as $c) {
            if ($c['critical'] && ! $c['ok']) {
                return false;
            }
        }

        return true;
    }

    /** @param \Closure(): array{0: bool, 1: string} $fn */
    private function check(string $name, bool $critical, \Closure $fn): array
    {
        try {
            [$ok, $detail] = $fn();
        } catch (Throwable $e) {
            [$ok, $detail] = [false, 'check failed: '.class_basename($e)];
        }

        return ['name' => $name, 'ok' => $ok, 'critical' => $critical, 'detail' => $detail];
    }
}
