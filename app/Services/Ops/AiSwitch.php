<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The kill switch for everything that costs money per use (model calls, vision, transcription). Two ways it closes:
 * by hand (`moneytalks:ai:pause`) or automatically when today's total AI cost passes the global daily budget.
 * Reports, balances, undo and every deterministic shortcut keep working while it is closed.
 */
class AiSwitch
{
    private const PAUSED_KEY = 'ops.ai.paused';

    public function pause(string $reason): void
    {
        Cache::forever(self::PAUSED_KEY, ['reason' => mb_substr($reason, 0, 120, 'UTF-8'), 'at' => now()->toIso8601String()]);
    }

    public function resume(): void
    {
        Cache::forget(self::PAUSED_KEY);
    }

    /** @return array{reason: string, at: string}|null */
    public function pausedBy(): ?array
    {
        return Cache::get(self::PAUSED_KEY);
    }

    /** Should AI work be refused right now? */
    public function blocked(): bool
    {
        return $this->pausedBy() !== null || $this->overBudget();
    }

    public function overBudget(): bool
    {
        $budget = (int) config('ai.limits.global_daily_budget_micros');
        if ($budget <= 0) {
            return false; // no budget configured
        }

        // Cached for a minute: this runs on every message.
        $spent = Cache::remember('ops.ai.spent_today.'.now()->format('Ymd'), 60, fn () => (int) DB::table('ai_requests')->where('created_at', '>=', now()->startOfDay())->sum('estimated_cost_micros'));

        return $spent >= $budget;
    }

    public function message(): string
    {
        return '⏸️ Smart reading is paused right now. Balance, reports and undo still work; please try again later.';
    }
}
