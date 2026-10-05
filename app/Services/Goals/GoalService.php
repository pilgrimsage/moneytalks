<?php

namespace App\Services\Goals;

use App\Domain\Ledger\AccountService;
use App\Domain\Ledger\Exceptions\LedgerException;
use App\Enums\AccountSubtype;
use App\Models\Goal;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Models\UserAlias;
use App\Services\Interpretation\AmountNormalizer;
use App\Services\Interpretation\DateResolver;
use App\Support\Money;
use App\Support\Text;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Savings goals. The goal is a target; the money lives in a "Goal: <name>" asset account, so saving towards it is an
 * ordinary (confirmed) transfer and progress is simply that account's balance. Progress is never stored.
 */
class GoalService
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly AmountNormalizer $amounts,
        private readonly DateResolver $dates,
    ) {}

    /** Handle a create_goal item: the target is item.amount, the name item.description, the optional target date item.date. */
    public function apply(User $user, array $item, string $text, CarbonImmutable $now): string
    {
        $name = $this->cleanName((string) ($item['description'] ?? $item['category'] ?? ''));
        if ($name === '') {
            return 'What is the goal for? For example "save 100000 for a bike by June".';
        }
        $existing = $this->find($user, $name);

        if (($item['action'] ?? null) === 'remove') {
            if (! $existing) {
                return "You don't have a goal called \"{$name}\".";
            }
            $existing->update(['status' => 'cancelled']);

            return "🗑️ Stopped the *{$existing->name}* goal. The money stays in its account ({$this->accountName($existing)}); move it with a transfer if you want it elsewhere.";
        }

        if (($item['amount'] ?? null) === null) {
            return "How much do you want to save for {$name}?";
        }
        try {
            $money = Money::parse((string) $item['amount'], $user->base_currency);
        } catch (InvalidArgumentException) {
            return 'I couldn\'t read the target amount. How much do you want to save?';
        }
        if (! $money->isPositive() || $money->minor > (int) config('ai.risk.max_amount_minor')) {
            return 'That target does not look right. How much do you want to save?';
        }
        if ($this->amounts->matches((string) $item['amount'], $text) !== true) {
            return "I read {$money->format()}, but I can't find that amount in your message. How much do you want to save?";
        }

        $targetDate = null;
        if (($item['date']['kind'] ?? 'none') !== 'none') {
            $d = $this->dates->resolve($item['date'], $user->timezone, $now);
            $today = $now->setTimezone($user->timezone)->startOfDay();
            if ($d === null || $d->lessThanOrEqualTo($today) || $d->greaterThan($today->addYears(30))) {
                return 'I couldn\'t work out a future target date. When do you want to reach it?';
            }
            $targetDate = $d->format('Y-m-d');
        }

        if ($existing) {
            $existing->update(['target_minor' => $money->minor, 'target_date' => $targetDate ?? $existing->target_date]);
            $goal = $existing->fresh();
            $verb = 'Updated';
        } else {
            try {
                $account = $this->accounts->create($user, 'Goal: '.$name, AccountSubtype::Goal);
            } catch (LedgerException $e) {
                return "I couldn't create that goal: {$e->getMessage()}";
            }
            foreach (array_unique([Text::normalize($name), Text::normalize($name.' goal'), Text::normalize($name.' fund')]) as $alias) {
                UserAlias::firstOrCreate(['user_id' => $user->id, 'entity_type' => 'account', 'alias' => $alias], ['entity_id' => $account->id, 'source' => 'user']);
            }
            $goal = Goal::create(['user_id' => $user->id, 'account_id' => $account->id, 'name' => $name, 'target_minor' => $money->minor, 'currency' => $user->base_currency, 'target_date' => $targetDate, 'status' => 'active']);
            $verb = 'Created';
        }

        return "🎯 {$verb} goal *{$goal->name}*: {$money->format()}".($goal->target_date ? ' by '.$goal->target_date->format('j M Y') : '')
            .".\nSave towards it with \"transfer 5000 to {$goal->name}\".";
    }

    /** @return list<array{name: string, target: int, saved: int, pct: int, date: ?string, monthly_needed: ?int, status: string}> */
    public function status(User $user, CarbonImmutable $now): array
    {
        $goals = Goal::where('user_id', $user->id)->whereIn('status', ['active', 'achieved'])->orderBy('name')->get();
        $balances = $this->accounts->balances($user->id, $goals->pluck('account_id')->all());
        $today = $now->setTimezone($user->timezone)->startOfDay();

        return $goals->map(function (Goal $g) use ($balances, $today) {
            $saved = max(0, $balances[$g->account_id]->minor ?? 0);
            $left = max(0, $g->target_minor - $saved);
            $months = $g->target_date ? max(1, (int) ceil($today->diffInDays($g->target_date, false) / 30.4375)) : null;

            return [
                'name' => $g->name, 'target' => $g->target_minor, 'saved' => $saved, 'pct' => intdiv($saved * 100, $g->target_minor),
                'date' => $g->target_date?->format('Y-m-d'), 'status' => $g->status,
                'monthly_needed' => $months !== null && $left > 0 ? intdiv($left + $months - 1, $months) : null,
            ];
        })->all();
    }

    /**
     * After a transfer into an account: if it belongs to a goal, the progress line (and a celebration when reached, once).
     */
    public function lineAfterTransfer(User $user, ?string $toAccountId): ?string
    {
        if ($toAccountId === null) {
            return null;
        }
        $goal = Goal::where('user_id', $user->id)->where('account_id', $toAccountId)->whereIn('status', ['active', 'achieved'])->first();
        if (! $goal) {
            return null;
        }

        $saved = max(0, $this->accounts->balance(LedgerAccount::findOrFail($toAccountId))->minor);
        $fmt = fn (int $m) => Money::ofMinor($m, $goal->currency)->format();
        $pct = intdiv($saved * 100, $goal->target_minor);

        if ($saved >= $goal->target_minor && $goal->status === 'active') {
            $goal->update(['status' => 'achieved']);

            return "🎉 Goal reached: *{$goal->name}* ({$fmt($saved)} of {$fmt($goal->target_minor)})!";
        }

        return "🎯 {$goal->name}: {$fmt($saved)} of {$fmt($goal->target_minor)} ({$pct}%).";
    }

    private function find(User $user, string $name): ?Goal
    {
        return Goal::where('user_id', $user->id)->whereIn('status', ['active', 'achieved'])->get()
            ->first(fn (Goal $g) => Text::normalize($g->name) === Text::normalize($name));
    }

    private function accountName(Goal $g): string
    {
        return (string) LedgerAccount::whereKey($g->account_id)->value('name');
    }

    private function cleanName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        $name = preg_replace('/^(a|an|the|my)\s+/iu', '', $name) ?? $name;

        return mb_substr(mb_convert_case($name, MB_CASE_TITLE, 'UTF-8'), 0, 60, 'UTF-8');
    }
}
