<?php

namespace App\Services\Conversation;

use App\Domain\Ledger\Exceptions\LedgerException;
use App\Domain\Ledger\LedgerService;
use App\Enums\TransactionSource;
use App\Enums\TransactionType;
use App\Models\ConversationState;
use App\Models\LedgerTransaction;
use App\Models\User;
use App\Services\Budgets\BudgetService;
use App\Services\Goals\GoalService;
use App\Services\Interpretation\CommandBuilder;
use App\Services\Interpretation\Decision;
use App\Services\Interpretation\ProposedPosting;
use App\Services\Interpretation\TransactionLocator;
use App\Services\Loans\LoanService;
use App\Services\Recurring\RecurringService;
use App\Services\WhatsApp\ReplyFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Carries out (or drops) the one thing a user was asked to confirm: record, correct or undo.
 * Everything goes through LedgerService with a key derived from the state id, so a double tap or a retried
 * job can never apply an action twice.
 */
class PendingActionService
{
    public const RECORD = 'record';

    public const CORRECT = 'correct';

    public const UNDO = 'undo';

    /** Create/stop a budget, goal, loan or recurring payment after the user confirmed (always for loans, and for anything sent by voice or photo). */
    public const APPLY = 'apply';

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly ConversationStore $store,
        private readonly TransactionLocator $locator,
        private readonly ReplyFormatter $replies,
        private readonly CommandBuilder $builder,
        private readonly BudgetService $budgets,
        private readonly RecurringService $recurring,
        private readonly GoalService $goals,
        private readonly LoanService $loans,
    ) {}

    /** Payload for a confirmation state. @param array<string, mixed> $extra */
    public static function payload(string $action, string $prompt, string $originWaId, ?ProposedPosting $posting = null, array $extra = []): array
    {
        return ['action' => $action, 'prompt' => $prompt, 'origin_wa_id' => $originWaId, 'posting' => $posting?->toArray()] + $extra;
    }

    /** The user confirmed. Returns the reply text. */
    public function confirm(User $user, ConversationState $state, string $triggerWaId): string
    {
        $p = $state->payload;
        $this->store->clear($user);

        try {
            return match ($p['action']) {
                self::RECORD => $this->record($user, $state, $p, $triggerWaId),
                self::CORRECT => $this->correct($user, $state, $p, $triggerWaId),
                self::UNDO => $this->undo($user, $p, $triggerWaId),
                self::APPLY => $this->apply($user, $p),
                default => $this->replies->expired(),
            };
        } catch (LedgerException $e) {
            Log::warning('pending_action.ledger_refused', ['action' => $p['action'], 'reason' => $e->getMessage()]);

            return "I couldn't do that: {$e->getMessage()} Nothing was changed.";
        }
    }

    public function cancel(User $user): string
    {
        $this->store->clear($user);

        return $this->replies->cancelled();
    }

    private function record(User $user, ConversationState $state, array $p, string $triggerWaId): string
    {
        $posting = ProposedPosting::fromArray($p['posting']);
        $posted = $this->ledger->post($this->builder->build($user, $posting, "confirm:{$state->id}", $p['origin_wa_id']));
        $linked = $posting->type === TransactionType::Expense && ! $posted->replayed ? $this->recurring->linkIfMatches($user, $posted->transaction, $posting->categoryId) : null;

        $goalLine = $posting->type === TransactionType::Transfer && ($line = $this->goals->lineAfterTransfer($user, $posting->toAccountId)) ? "\n".$line : '';
        $loanLine = $posting->type === TransactionType::EmiPayment && ($l = $this->loans->afterPayment($user, $posting->toAccountId)) ? "\n".$l : '';
        $alerts = in_array($posting->type, [TransactionType::Expense, TransactionType::SplitExpense], true)
            ? $this->budgets->alertsAfterExpense($user, $posting->categoryId, $posting->occurredOn, CarbonImmutable::now('UTC'))
            : [];

        return $this->replies->recorded($posting).($linked ? "\n".$linked : '').$goalLine.$loanLine.($alerts === [] ? '' : "\n\n".implode("\n", $alerts));
    }

    private function correct(User $user, ConversationState $state, array $p, string $triggerWaId): string
    {
        $posting = ProposedPosting::fromArray($p['posting']);
        $original = LedgerTransaction::where('user_id', $user->id)->findOrFail($p['tx_id']);
        $before = $this->locator->describe($original);

        $this->ledger->correct($original->id, $this->builder->build($user, $posting, "confirm:{$state->id}", $p['origin_wa_id'], $original), 'correction requested via WhatsApp');

        return $this->replies->corrected($before, $posting->describe());
    }

    private function apply(User $user, array $p): string
    {
        $item = (array) $p['item'];
        $text = (string) $p['text'];
        $now = CarbonImmutable::now('UTC');

        return match ($p['intent']) {
            Decision::LOAN => $this->loans->create($user, $item, $text, $now),
            Decision::GOAL => $this->goals->apply($user, $item, $text, $now),
            Decision::BUDGET => $this->budgets->apply($user, $item, $text, $now),
            Decision::RECURRING => isset($item['rr_remove']) ? $this->recurring->cancel($user, $item['rr_remove']) : $this->recurring->create($user, $item['rr']),
            default => $this->replies->expired(),
        };
    }

    private function undo(User $user, array $p, string $triggerWaId): string
    {
        $tx = LedgerTransaction::where('user_id', $user->id)->find($p['tx_id']);
        if (! $tx) {
            return $this->replies->nothingToUndo();
        }
        $description = $this->locator->describe($tx);
        $this->ledger->reverse($user->id, $tx->id, 'undo requested via WhatsApp', TransactionSource::WhatsappText, $triggerWaId);

        return $this->replies->undone($description, $tx->corrects_id !== null);
    }
}
