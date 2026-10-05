<?php

namespace App\Services\Recurring;

use App\Domain\Ledger\DTO\PostingCommand;
use App\Domain\Ledger\Exceptions\LedgerException;
use App\Domain\Ledger\LedgerService;
use App\Enums\TransactionSource;
use App\Enums\TransactionType;
use App\Models\LedgerTransaction;
use App\Models\RecurringOccurrence;
use App\Models\RecurringRule;
use App\Models\User;
use App\Services\Budgets\BudgetService;
use App\Services\WhatsApp\OutboundMessageService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Repeating payments. A rule never writes to the ledger: when one falls due it makes an occurrence and asks the user
 * ("Paid" / "Skip"); only a tap (or the user logging the same payment themselves) settles it, through LedgerService.
 */
class RecurringService
{
    public const FREQUENCIES = ['daily', 'weekly', 'monthly', 'yearly'];

    /** How many reminders one occurrence gets in total, and the wait between them. */
    private const MAX_REMINDERS = 2;

    private const REMIND_AFTER_DAYS = 2;

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly OutboundMessageService $out,
        private readonly BudgetService $budgets,
    ) {}

    /** Date of occurrence number $cycle: the anchor stepped forward, never drifting off the day of month. */
    public static function dueOn(CarbonImmutable $anchor, string $frequency, int $cycle): CarbonImmutable
    {
        return match ($frequency) {
            'daily' => $anchor->addDays($cycle),
            'weekly' => $anchor->addWeeks($cycle),
            'monthly' => $anchor->addMonthsNoOverflow($cycle),
            'yearly' => $anchor->addYearsNoOverflow($cycle),
            default => throw new \InvalidArgumentException("Unknown frequency {$frequency}"),
        };
    }

    /** What a payment costs per month, for the "subscriptions" total. */
    public static function monthlyMinor(int $minor, string $frequency): int
    {
        return match ($frequency) {
            'daily' => intdiv($minor * 365, 12), 'weekly' => intdiv($minor * 52, 12),
            'yearly' => intdiv($minor, 12), default => $minor,
        };
    }

    /**
     * @param  array{name: string, type: string, minor: int, category_id: string, merchant_id: ?string, account_id: string, frequency: string, first_due: string}  $rr
     */
    public function create(User $user, array $rr): string
    {
        $existing = RecurringRule::where('user_id', $user->id)->where('status', 'active')->whereRaw('LOWER(name) = ?', [mb_strtolower($rr['name'], 'UTF-8')])->first();
        $fields = [
            'type' => $rr['type'], 'amount_minor' => $rr['minor'], 'currency' => $user->base_currency, 'category_id' => $rr['category_id'],
            'merchant_id' => $rr['merchant_id'], 'account_id' => $rr['account_id'], 'frequency' => $rr['frequency'],
            'anchor_on' => $rr['first_due'], 'cycle' => 0, 'next_due_on' => $rr['first_due'],
        ];
        $existing ? $existing->update($fields) : RecurringRule::create(['user_id' => $user->id, 'name' => $rr['name'], 'status' => 'active'] + $fields);

        return ($existing ? '🔁 Updated' : '🔁 Added').' *'.$rr['name'].'*: '.Money::ofMinor($rr['minor'], $user->base_currency)->format().' '.$rr['frequency']
            .'. Next due '.CarbonImmutable::parse($rr['first_due'])->format('j M').". I'll remind you, and nothing is recorded until you tap Paid.";
    }

    public function cancel(User $user, string $name): string
    {
        $needle = mb_strtolower(trim($name), 'UTF-8');
        $rules = RecurringRule::where('user_id', $user->id)->where('status', 'active')->get()
            ->filter(fn (RecurringRule $r) => $needle !== '' && str_contains(mb_strtolower($r->name, 'UTF-8'), $needle))->values();

        if ($rules->isEmpty()) {
            return "I couldn't find a recurring payment called \"{$name}\". Ask \"what are my subscriptions?\" to see them.";
        }
        if ($rules->count() > 1) {
            return 'Which one: '.$rules->pluck('name')->implode(', ').'?';
        }

        $rule = $rules->first();
        DB::transaction(function () use ($rule) {
            $rule->update(['status' => 'cancelled']);
            RecurringOccurrence::where('rule_id', $rule->id)->where('status', 'due')->update(['status' => 'cancelled']);
        });

        return "🛑 Stopped *{$rule->name}*. Nothing already recorded was changed.";
    }

    /**
     * The scheduler's step: open an occurrence for every rule that is due and remind the user (and nag once more
     * about one still waiting). Safe to run any number of times: an occurrence and its reminder are keyed.
     *
     * @return int reminders sent
     */
    public function tick(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now('UTC');
        $sent = 0;

        foreach (User::query()->whereHas('recurringRules', fn ($q) => $q->where('status', 'active'))->get() as $user) {
            $today = $now->setTimezone($user->timezone)->startOfDay();

            $rules = RecurringRule::where('user_id', $user->id)->where('status', 'active')->where('next_due_on', '<=', $today->format('Y-m-d'))->get();
            foreach ($rules as $rule) {
                $open = RecurringOccurrence::where('rule_id', $rule->id)->where('status', 'due')->exists();
                if ($open) {
                    continue; // one open occurrence per rule: never a flood after a few quiet days
                }
                $occ = RecurringOccurrence::firstOrCreate(['rule_id' => $rule->id, 'due_on' => $rule->next_due_on->format('Y-m-d')], ['user_id' => $user->id, 'status' => 'due']);
                if ($occ->status === 'due') {
                    $sent += $this->remind($user, $rule, $occ, $now) ? 1 : 0;
                }
            }

            $waiting = RecurringOccurrence::where('user_id', $user->id)->where('status', 'due')->where('reminders_sent', '<', self::MAX_REMINDERS)
                ->where('reminders_sent', '>', 0)->where('last_reminded_at', '<=', $now->subDays(self::REMIND_AFTER_DAYS))->get();
            foreach ($waiting as $occ) {
                $sent += $this->remind($user, RecurringRule::findOrFail($occ->rule_id), $occ, $now) ? 1 : 0;
            }
        }

        return $sent;
    }

    /** The user tapped Paid. Posts the ordinary transaction (once, however often the button is tapped). */
    public function pay(User $user, string $occurrenceId, ?string $waMessageId): string
    {
        [$occ, $rule] = $this->load($user, $occurrenceId);
        if (! $occ) {
            return 'That reminder has expired or was already handled.';
        }

        try {
            $result = $this->ledger->post(new PostingCommand(
                userId: $user->id, type: TransactionType::from($rule->type), money: Money::ofMinor($rule->amount_minor, $rule->currency),
                occurredOn: $occ->due_on->format('Y-m-d'), accountId: $rule->account_id, idempotencyKey: "recurring:{$occ->id}",
                categoryId: $rule->category_id, merchantId: $rule->merchant_id, description: $rule->name,
                source: TransactionSource::System, waMessageId: $waMessageId, actorType: 'user',
            ));
        } catch (LedgerException $e) {
            return "I couldn't record that: {$e->getMessage()} Nothing was recorded.";
        }

        $this->settle($rule, $occ, 'paid', $result->transaction->id, $user);
        $alerts = $rule->type === 'expense' ? $this->budgets->alertsAfterExpense($user, $rule->category_id, $occ->due_on->format('Y-m-d'), CarbonImmutable::now('UTC')) : [];

        return "✅ Recorded {$rule->name}: ".Money::ofMinor($rule->amount_minor, $rule->currency)->format().' ('.$occ->due_on->format('j M').').'
            .' Next due '.$rule->fresh()->next_due_on->format('j M').($alerts === [] ? '' : "\n\n".implode("\n", $alerts));
    }

    public function skip(User $user, string $occurrenceId): string
    {
        [$occ, $rule] = $this->load($user, $occurrenceId);
        if (! $occ) {
            return 'That reminder has expired or was already handled.';
        }
        $this->settle($rule, $occ, 'skipped', null, $user);

        return "⏭️ Skipped {$rule->name} for ".$occ->due_on->format('j M').'. Next due '.$rule->fresh()->next_due_on->format('j M').'.';
    }

    /**
     * The user logged the payment themselves ("paid netflix 649"): settle the matching open occurrence instead of
     * reminding them about something already done. Same amount, same merchant or category, due within 5 days.
     */
    public function linkIfMatches(User $user, LedgerTransaction $tx, ?string $categoryId): ?string
    {
        $date = $tx->occurred_on;
        $amount = (int) $tx->debit_total_minor;

        $occ = RecurringOccurrence::where('user_id', $user->id)->where('status', 'due')
            ->whereBetween('due_on', [$date->subDays(5)->format('Y-m-d'), $date->addDays(5)->format('Y-m-d')])->get()
            ->first(function (RecurringOccurrence $o) use ($tx, $amount, $categoryId) {
                $rule = RecurringRule::find($o->rule_id);

                return $rule && $rule->type === 'expense' && $rule->amount_minor === $amount
                    && (($rule->merchant_id !== null && $rule->merchant_id === $tx->merchant_id) || ($categoryId !== null && $rule->category_id === $categoryId));
            });
        if (! $occ) {
            return null;
        }

        $rule = RecurringRule::findOrFail($occ->rule_id);
        $this->settle($rule, $occ, 'paid', $tx->id, $user);

        return "🔁 That matches your *{$rule->name}* payment, so I marked it as paid.";
    }

    /** @return list<array{name: string, minor: int, frequency: string, next_due_on: string, monthly_minor: int}> */
    public function subscriptions(User $user): array
    {
        return RecurringRule::where('user_id', $user->id)->where('status', 'active')->where('type', 'expense')->orderBy('name')->get()->map(fn (RecurringRule $r) => [
            'name' => $r->name, 'minor' => $r->amount_minor, 'frequency' => $r->frequency,
            'next_due_on' => $r->next_due_on->format('Y-m-d'), 'monthly_minor' => self::monthlyMinor($r->amount_minor, $r->frequency),
        ])->all();
    }

    /** @return list<array{name: string, minor: int, due_on: string, type: string}> due within the next $days days (overdue ones first) */
    public function upcoming(User $user, CarbonImmutable $now, int $days = 30): array
    {
        $until = $now->setTimezone($user->timezone)->startOfDay()->addDays($days)->format('Y-m-d');

        return RecurringRule::where('user_id', $user->id)->where('status', 'active')->where('next_due_on', '<=', $until)->orderBy('next_due_on')->orderBy('name')->get()
            ->map(fn (RecurringRule $r) => ['name' => $r->name, 'minor' => $r->amount_minor, 'due_on' => $r->next_due_on->format('Y-m-d'), 'type' => $r->type])->all();
    }

    private function remind(User $user, RecurringRule $rule, RecurringOccurrence $occ, CarbonImmutable $now): bool
    {
        $n = $occ->reminders_sent + 1;
        $when = $occ->due_on->isSameDay($now->setTimezone($user->timezone)) ? 'today' : 'on '.$occ->due_on->format('j M');
        $text = '🔔 *'.$rule->name.'* '.Money::ofMinor($rule->amount_minor, $rule->currency)->format().' is due '.$when.'.';

        $row = $this->out->sendButtons($user, $text, [['id' => "paid:{$occ->id}", 'title' => 'Paid'], ['id' => "skip:{$occ->id}", 'title' => 'Skip']], "recurring:{$occ->id}:{$n}");
        $occ->update(['reminders_sent' => $n, 'last_reminded_at' => $now]); // counted even if outside the window: the failure is visible in the outbox

        return $row->status->value !== 'failed';
    }

    /** @return array{0: ?RecurringOccurrence, 1: ?RecurringRule} */
    private function load(User $user, string $occurrenceId): array
    {
        $occ = RecurringOccurrence::where('user_id', $user->id)->whereKey($occurrenceId)->where('status', 'due')->first();
        $rule = $occ ? RecurringRule::where('user_id', $user->id)->whereKey($occ->rule_id)->where('status', 'active')->first() : null;

        return $rule ? [$occ, $rule] : [null, null];
    }

    /** Mark an occurrence done and move the rule to its next due date (never into the past). */
    private function settle(RecurringRule $rule, RecurringOccurrence $occ, string $status, ?string $transactionId, User $user): void
    {
        DB::transaction(function () use ($rule, $occ, $status, $transactionId, $user) {
            $occ->update(['status' => $status, 'transaction_id' => $transactionId]);

            $today = CarbonImmutable::now($user->timezone)->startOfDay();
            $cycle = $rule->cycle + 1;
            $next = self::dueOn($rule->anchor_on, $rule->frequency, $cycle);
            while ($next->lessThan($today) && $cycle < $rule->cycle + 1000) { // long overdue: drop the missed ones, don't flood
                $next = self::dueOn($rule->anchor_on, $rule->frequency, ++$cycle);
            }
            $rule->update(['cycle' => $cycle, 'next_due_on' => $next->format('Y-m-d')]);
        });
    }
}
