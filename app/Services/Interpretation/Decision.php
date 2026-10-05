<?php

namespace App\Services\Interpretation;

/** What the application decided to do with one item of the model's proposal. Only RECORD touches the ledger. */
final class Decision
{
    public const RECORD = 'record';

    public const CLARIFY = 'clarify';       // ask the user; nothing recorded

    public const UNSUPPORTED = 'unsupported'; // understood, but not something we do (yet); nothing recorded

    public const HELP = 'help';

    public const CONFIRM = 'confirm';       // validated, but the user must tap Confirm first; nothing recorded yet

    public const UNDO = 'undo';             // the user wants a transaction reversed (target resolved by the handler)

    public const CORRECT = 'correct';       // the user wants a transaction changed (target resolved by the handler)

    public const QUERY = 'query';           // a question or report: answered from the ledger by SQL, never by the model

    public const BUDGET = 'budget';         // set, change or remove a monthly budget (handled by BudgetService)

    public const LOAN = 'loan';             // start tracking a loan (handled by LoanService)

    public const GOAL = 'goal';             // create, change or stop a savings goal (handled by GoalService)

    public const RECURRING = 'recurring';   // create or stop a repeating payment (validated here, stored by RecurringService)

    public const EXPORT = 'export';         // a file export of the user's own transactions

    public function __construct(
        public readonly string $kind,
        public readonly int $index = 0,
        public readonly string $reason = '',        // machine-readable: why (for metrics and evals)
        public readonly string $message = '',       // user-facing text for non-record decisions
        public readonly ?ProposedPosting $posting = null,
        public readonly float $score = 0.0,
        /** For CLARIFY: which detail is missing, when the user's next message can simply supply it. */
        public readonly ?string $awaiting = null,
        /** The model's item (validated shape), kept so a clarification can be completed without another AI call. */
        public readonly ?array $item = null,
    ) {}

    public static function clarify(int $i, string $reason, string $message, float $score = 0.0, ?string $awaiting = null, ?array $item = null): self
    {
        return new self(self::CLARIFY, $i, $reason, $message, null, $score, $awaiting, $item);
    }

    public static function confirm(int $i, string $reason, string $message, ProposedPosting $posting, float $score): self
    {
        return new self(self::CONFIRM, $i, $reason, $message, $posting, $score);
    }

    public static function intent(string $kind, int $i, array $item): self
    {
        return new self($kind, $i, $kind, '', null, 0.0, null, $item);
    }

    public static function unsupported(int $i, string $reason, string $message): self
    {
        return new self(self::UNSUPPORTED, $i, $reason, $message);
    }

    public static function record(int $i, ProposedPosting $posting, float $score): self
    {
        return new self(self::RECORD, $i, 'ok', '', $posting, $score);
    }
}
