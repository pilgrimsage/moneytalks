<?php

namespace App\Services\Interpretation;

use App\Domain\Ledger\DebtService;
use App\Enums\AccountSubtype;
use App\Enums\CategoryKind;
use App\Enums\EntityType;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\Category;
use App\Models\LedgerAccount;
use App\Models\Merchant;
use App\Models\User;
use App\Services\Accounts\AccountSetupService;
use App\Services\AI\Prompts\TransactionParser as P;
use App\Services\Loans\LoanService;
use App\Services\Recurring\RecurringService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Turns the model's PROPOSAL into decisions using deterministic checks only (docs/ai.md section 9).
 * The model is never trusted: amounts are cross-checked against the user's text, dates are resolved
 * here, names are resolved against the user's own records, and anything doubtful becomes a question.
 * Only a Decision::RECORD may reach the ledger.
 */
class ProposalValidator
{
    /** Event types the ledger can record today; the rest are recognised but declined with an explanation. */
    private const SUPPORTED = ['expense', 'income', 'transfer'];

    /** Event types about money between people and cards (M8). */
    private const DEBT_TYPES = [
        'lend' => TransactionType::Lend, 'borrow' => TransactionType::Borrow,
        'repayment_received' => TransactionType::RepaymentIn, 'repayment_made' => TransactionType::RepaymentOut,
        'split_expense' => TransactionType::SplitExpense, 'credit_card_payment' => TransactionType::CcPayment,
    ];

    private const LABEL = [
        'refund' => 'a refund',
        'opening_balance' => 'an opening balance',
    ];

    private const INTENT_LABEL = [
        'update_setting' => 'changing settings',
    ];

    public function __construct(
        private readonly EntityResolver $resolver,
        private readonly AmountNormalizer $amounts,
        private readonly DateResolver $dates,
        private readonly DebtService $debts,
        private readonly LoanService $loans,
        private readonly AccountSetupService $accountSetup,
    ) {}

    /**
     * Structural check of the model's JSON. The API's schema already enforces this; we re-check because
     * the schema is a guardrail, not a trust boundary. Returns an error description or null.
     *
     * @param  array<string, mixed>  $envelope
     */
    public function structuralError(array $envelope): ?string
    {
        if (! isset($envelope['items']) || ! is_array($envelope['items']) || ! array_is_list($envelope['items'])) {
            return 'items missing';
        }
        foreach ($envelope['items'] as $i => $item) {
            if (! is_array($item)) {
                return "item {$i} is not an object";
            }
            if (! in_array($item['intent'] ?? null, P::INTENTS, true)) {
                return "item {$i}: unknown intent";
            }
            if (($item['event_type'] ?? null) !== null && ! in_array($item['event_type'], P::EVENT_TYPES, true)) {
                return "item {$i}: unknown event_type";
            }
            if (($item['amount'] ?? null) !== null && ! is_string($item['amount']) && ! is_int($item['amount'])) {
                return "item {$i}: amount must be a string";
            }
            if (isset($item['date']) && (! is_array($item['date']) || ! in_array($item['date']['kind'] ?? null, P::DATE_KINDS, true))) {
                return "item {$i}: bad date";
            }
            foreach (['category', 'merchant', 'account', 'to_account', 'counterparty', 'description', 'currency', 'payment_method'] as $f) {
                if (isset($item[$f]) && ! is_string($item[$f])) {
                    return "item {$i}: {$f} must be a string";
                }
            }
            if (isset($item['missing_fields']) && (! is_array($item['missing_fields']) || array_diff($item['missing_fields'], P::MISSING) !== [])) {
                return "item {$i}: bad missing_fields";
            }
            if (isset($item['confidence']) && ! is_numeric($item['confidence'])) {
                return "item {$i}: confidence must be a number";
            }
            if (($item['target_kind'] ?? null) !== null && ! in_array($item['target_kind'], P::TARGET_KINDS, true)) {
                return "item {$i}: bad target_kind";
            }
            if (($item['query_metric'] ?? null) !== null && ! in_array($item['query_metric'], P::QUERY_METRICS, true)) {
                return "item {$i}: bad query_metric";
            }
            if (isset($item['period']) && (! is_array($item['period']) || ! in_array($item['period']['kind'] ?? null, P::PERIOD_KINDS, true))) {
                return "item {$i}: bad period";
            }
            if (isset($item['compare_period']) && (! is_array($item['compare_period']) || ! in_array($item['compare_period']['kind'] ?? null, P::PERIOD_KINDS, true))) {
                return "item {$i}: bad compare_period";
            }
            if (($item['group_by'] ?? null) !== null && ! in_array($item['group_by'], P::GROUP_BYS, true)) {
                return "item {$i}: bad group_by";
            }
            if (($item['export_format'] ?? null) !== null && ! in_array($item['export_format'], P::EXPORT_FORMATS, true)) {
                return "item {$i}: bad export_format";
            }
            if (isset($item['participants'])) {
                if (! is_array($item['participants']) || ! array_is_list($item['participants'])) {
                    return "item {$i}: participants must be a list";
                }
                foreach ($item['participants'] as $pt) {
                    if (! is_array($pt) || ! is_string($pt['name'] ?? null) || (isset($pt['amount']) && ! is_string($pt['amount']) && ! is_int($pt['amount']))) {
                        return "item {$i}: bad participant";
                    }
                }
            }
            if (isset($item['due_date']) && (! is_array($item['due_date']) || ! in_array($item['due_date']['kind'] ?? null, P::DATE_KINDS, true))) {
                return "item {$i}: bad due_date";
            }
            if (($item['recurrence'] ?? null) !== null && ! in_array($item['recurrence'], P::RECURRENCES, true)) {
                return "item {$i}: bad recurrence";
            }
            if (($item['action'] ?? null) !== null && ! in_array($item['action'], P::ACTIONS, true)) {
                return "item {$i}: bad action";
            }
            if (isset($item['limit']) && ! is_int($item['limit'])) {
                return "item {$i}: limit must be an integer";
            }
            if (isset($item['search_text']) && ! is_string($item['search_text'])) {
                return "item {$i}: search_text must be a string";
            }
            foreach (['target_amount', 'target_text'] as $f) {
                if (isset($item[$f]) && ! is_string($item[$f]) && ! is_int($item[$f])) {
                    return "item {$i}: {$f} must be a string";
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $envelope  already passed structuralError()
     * @return list<Decision>
     */
    public function decide(User $user, array $envelope, string $text, CarbonImmutable $now): array
    {
        $items = array_slice($envelope['items'], 0, (int) config('ai.limits.max_items_per_message'));
        if ($items === []) {
            return [Decision::unsupported(0, 'no_items', $this->didNotUnderstand())];
        }

        $out = [];
        foreach ($items as $i => $item) {
            $out[] = $this->decideItem($user, $item, $i, $text, $now);
        }

        return $out;
    }

    /** One item -> one decision. Public so a clarification can be completed and re-validated without another AI call. */
    public function decideItem(User $user, array $item, int $i, string $text, CarbonImmutable $now): Decision
    {
        return match ($item['intent']) {
            'record_event' => $this->decideRecord($user, $item, $i, $text, $now),
            'undo_transaction' => Decision::intent(Decision::UNDO, $i, $item),
            'correct_transaction' => Decision::intent(Decision::CORRECT, $i, $item),
            'query', 'report' => Decision::intent(Decision::QUERY, $i, $item),
            'export' => Decision::intent(Decision::EXPORT, $i, $item),
            'create_budget' => Decision::intent(Decision::BUDGET, $i, $item),
            'create_goal' => Decision::intent(Decision::GOAL, $i, $item),
            'create_loan' => Decision::intent(Decision::LOAN, $i, $item),
            'create_account' => $this->decideAccount($user, $item, $i, $text),
            'create_recurring' => $this->decideRecurring($user, $item, $i, $text, $now),
            'help' => new Decision(Decision::HELP, $i, 'help'),
            'unknown' => Decision::unsupported($i, 'unknown', $this->didNotUnderstand()),
            default => Decision::unsupported($i, 'intent_not_available',
                'I understood that as '.(self::INTENT_LABEL[$item['intent']] ?? $item['intent']).', which I can\'t do yet. Nothing was recorded.'),
        };
    }

    /** An EMI instalment on a tracked loan: split into principal and interest by LoanService, always confirmed with a tap. */
    private function decideEmi(User $user, array $item, int $i, string $text, CarbonImmutable $now): Decision
    {
        $stated = null;
        if (($item['amount'] ?? null) !== null && ! in_array('amount', $item['missing_fields'] ?? [], true)) {
            try {
                $stated = Money::parse((string) $item['amount'], $user->base_currency);
            } catch (InvalidArgumentException) {
                return Decision::clarify($i, 'amount_invalid', 'I couldn\'t read the amount. How much was the EMI?', 0.0, 'amount', $item);
            }
            if (! $stated->isPositive() || $stated->minor > (int) config('ai.risk.max_amount_minor') || $this->amounts->matches((string) $item['amount'], $text) === false) {
                return Decision::clarify($i, 'amount_not_in_text', 'I can\'t find that amount in your message. How much was the EMI?', 0.0, 'amount', $item);
            }
        }

        $date = $this->dates->resolve($item['date'] ?? ['kind' => 'none'], $user->timezone, $now);
        $today = $now->setTimezone($user->timezone)->startOfDay();
        if ($date === null || $date->greaterThan($today->addDays((int) config('ai.risk.max_future_days'))) || $date->lessThan($today->subDays((int) config('ai.risk.max_past_days')))) {
            return Decision::clarify($i, 'date_invalid', 'I couldn\'t work out the date. Which date was it?');
        }

        $plan = $this->loans->plan($user, (string) ($item['description'] ?? $item['to_account'] ?? $item['counterparty'] ?? $item['category'] ?? ''), $stated);
        if (is_string($plan)) {
            return Decision::clarify($i, 'loan_plan', $plan);
        }
        [$account, $err] = $this->account($user, TransactionType::Income, $item['account'] ?? null, $item['payment_method'] ?? null, $i, 'from', $item);
        if ($err) {
            return $err;
        }

        $confidence = max(0.0, min(1.0, (float) ($item['confidence'] ?? 0)));
        $score = 0.60 * $confidence + 0.25 * ($stated === null || $this->amounts->matches((string) $item['amount'], $text) === true ? 1.0 : 0.7) + 0.15;
        if ($score < (float) config('ai.risk.confirm_min_score')) {
            return Decision::clarify($i, 'low_confidence', 'I\'m not sure I understood that. Nothing was recorded. Could you say it again with a bit more detail?', $score);
        }

        $posting = new ProposedPosting(
            type: TransactionType::EmiPayment, money: Money::ofMinor($plan['total'], $user->base_currency), occurredOn: $date->format('Y-m-d'),
            accountId: $account->id, accountName: $account->name, toAccountId: $plan['account']->id, toAccountName: $plan['loan']->name,
            categoryId: $plan['interest'] > 0 ? $this->loans->interestCategoryId($user) : null, categoryName: $plan['interest'] > 0 ? 'Loan Interest' : null, merchantId: null,
            description: $plan['loan']->name.' EMI', paymentMethod: null, confidence: $confidence, isToday: $date->isSameDay($today), interestMinor: $plan['interest'],
        );

        return Decision::confirm($i, 'emi', 'Record '.$posting->describe().'?', $posting, $score);
    }

    /** A repeating payment, or stopping one. Stored by RecurringService; nothing is posted to the ledger here. */
    /** "Add the HDFC account with 52340" / "opening balance on HDFC is 52340": validated here, applied only after a Confirm tap. */
    private function decideAccount(User $user, array $item, int $i, string $text): Decision
    {
        $result = $this->accountSetup->plan($user, $item, $text);

        return isset($result['plan'])
            ? Decision::intent(Decision::ACCOUNT, $i, ['acct' => $result['plan']] + $item)
            : Decision::clarify($i, $result['reason'], $result['message']);
    }

    private function decideRecurring(User $user, array $item, int $i, string $text, CarbonImmutable $now): Decision
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) ($item['merchant'] ?? '')) ?: '');
        $name = $name !== '' ? $name : trim((string) ($item['description'] ?? $item['category'] ?? ''));

        if (($item['action'] ?? null) === 'remove') {
            return $name === ''
                ? Decision::clarify($i, 'recurring_name_missing', 'Which recurring payment should I stop? For example "cancel Netflix".')
                : Decision::intent(Decision::RECURRING, $i, ['rr_remove' => $name] + $item);
        }

        $typeName = ($item['event_type'] ?? null) ?: 'expense';
        if (! in_array($typeName, ['expense', 'income'], true)) {
            return Decision::unsupported($i, 'recurring_type', 'I can only repeat expenses and income (like rent or salary) for now. Nothing was set up.');
        }
        $type = TransactionType::from($typeName);

        if (($item['amount'] ?? null) === null || in_array('amount', $item['missing_fields'] ?? [], true)) {
            return Decision::clarify($i, 'amount_missing', 'How much is each payment?', 0.0, 'amount', $item);
        }
        try {
            $money = Money::parse((string) $item['amount'], $user->base_currency);
        } catch (InvalidArgumentException) {
            return Decision::clarify($i, 'amount_invalid', 'I couldn\'t read the amount. How much is each payment?', 0.0, 'amount', $item);
        }
        if (! $money->isPositive() || $money->minor > (int) config('ai.risk.max_amount_minor')) {
            return Decision::clarify($i, 'amount_invalid', 'That amount does not look right. How much is each payment?', 0.0, 'amount', $item);
        }
        // A rule repeats forever, so the amount must be provably in the message (not merely "not contradicted").
        if ($this->amounts->matches((string) $item['amount'], $text) !== true) {
            return Decision::clarify($i, 'amount_not_in_text', "I read {$money->format()}, but I can't find that amount in your message. How much is each payment?", 0.0, 'amount', $item);
        }

        $frequency = $item['recurrence'] ?? null;
        if (! in_array($frequency, P::RECURRENCES, true)) {
            return Decision::clarify($i, 'recurrence_missing', 'How often is it: daily, weekly, monthly or yearly?');
        }
        if ($name === '') {
            return Decision::clarify($i, 'recurring_name_missing', 'What is this payment for? For example "Netflix 649 every month".');
        }

        $merchant = null;
        if (! empty($item['merchant'])) {
            $r = $this->resolver->resolve($user->id, EntityType::Merchant, (string) $item['merchant']);
            $merchant = $r->isResolved() ? Merchant::find($r->entityId) : null;
        }
        $kind = $type === TransactionType::Income ? CategoryKind::Income : CategoryKind::Expense;
        $named = trim((string) ($item['category'] ?? ''));
        $category = null;
        if ($named !== '') {
            $r = $this->resolver->resolve($user->id, EntityType::Category, $named, $kind);
            if ($r->isAmbiguous()) {
                return Decision::clarify($i, 'category_ambiguous', 'Which category did you mean for "'.$named.'": '.$this->orList(array_column($r->candidates, 'name')).'?', 0.0, 'category', $item);
            }
            $category = $r->isResolved() ? Category::where('user_id', $user->id)->find($r->entityId) : null;
            if (! $category) {
                return Decision::clarify($i, 'category_unknown', "I don't have a category called \"{$named}\". Which of your categories fits? (for example ".$this->sampleCategories($user, $kind).')', 0.0, 'category', $item);
            }
        } elseif ($merchant?->default_category_id) {
            $category = Category::where('user_id', $user->id)->where('kind', $kind->value)->find($merchant->default_category_id);
        }
        if (! $category) {
            return Decision::clarify($i, 'category_missing', "Which category is {$name}?", 0.0, 'category', $item);
        }

        [$account, $err] = $this->account($user, $type, $item['account'] ?? null, $item['payment_method'] ?? null, $i, 'from', $item);
        if ($err) {
            return $err;
        }

        // First due date: the date the user gave (moved forward if it already passed), otherwise one period from today.
        $today = $now->setTimezone($user->timezone)->startOfDay();
        $given = ($item['date']['kind'] ?? 'none') !== 'none' ? $this->dates->resolve($item['date'], $user->timezone, $now) : null;
        $anchor = $given ?? $today;
        $cycle = $given === null ? 1 : 0;
        $due = RecurringService::dueOn($anchor, $frequency, $cycle);
        while ($due->lessThan($today) && $cycle < 5000) {
            $due = RecurringService::dueOn($anchor, $frequency, ++$cycle);
        }

        return Decision::intent(Decision::RECURRING, $i, ['rr' => [
            'name' => $merchant->name ?? mb_convert_case($name, MB_CASE_TITLE, 'UTF-8'), 'type' => $type->value, 'minor' => $money->minor,
            'category_id' => $category->id, 'merchant_id' => $merchant?->id, 'account_id' => $account->id,
            'frequency' => $frequency, 'first_due' => $due->format('Y-m-d'),
        ]] + $item);
    }

    /** Lending, borrowing, repayments, split expenses and card bill payments. */
    private function decideDebt(User $user, array $item, int $i, string $text, CarbonImmutable $now, TransactionType $type): Decision
    {
        $split = $type === TransactionType::SplitExpense;
        $person = in_array($type, [TransactionType::Lend, TransactionType::Borrow, TransactionType::RepaymentIn, TransactionType::RepaymentOut], true);

        if (($item['amount'] ?? null) === null || in_array('amount', $item['missing_fields'] ?? [], true)) {
            return Decision::clarify($i, 'amount_missing', 'How much was it?', 0.0, 'amount', $item);
        }
        $currency = strtoupper(trim((string) ($item['currency'] ?? ''))) ?: $user->base_currency;
        if ($currency !== $user->base_currency) {
            return Decision::unsupported($i, 'currency_not_supported', "I only record amounts in {$user->base_currency} for now, so nothing was recorded.");
        }
        try {
            $money = Money::parse((string) $item['amount'], $currency);
        } catch (InvalidArgumentException) {
            return Decision::clarify($i, 'amount_invalid', 'I couldn\'t read the amount. How much was it?', 0.0, 'amount', $item);
        }
        if (! $money->isPositive()) {
            return Decision::clarify($i, 'amount_not_positive', 'The amount must be more than zero. How much was it?', 0.0, 'amount', $item);
        }
        if ($money->minor > (int) config('ai.risk.max_amount_minor')) {
            return Decision::clarify($i, 'amount_too_large', 'That amount looks too large to be right. Please check it and send it again.');
        }
        $verified = $this->amounts->matches((string) $item['amount'], $text);
        if ($verified === false) {
            return Decision::clarify($i, 'amount_not_in_text', "I read {$money->format()}, but I can't find that amount in your message. How much was it?", 0.0, 'amount', $item);
        }

        $date = $this->dates->resolve($item['date'] ?? ['kind' => 'none'], $user->timezone, $now);
        $today = $now->setTimezone($user->timezone)->startOfDay();
        if ($date === null || $date->greaterThan($today->addDays((int) config('ai.risk.max_future_days')))
            || $date->lessThan($today->subDays((int) config('ai.risk.max_past_days')))) {
            return Decision::clarify($i, 'date_invalid', 'I couldn\'t work out the date. Which date was it?');
        }

        $dueOn = null;
        if (in_array($type, [TransactionType::Lend, TransactionType::Borrow], true) && ! empty($item['due_date']) && ($item['due_date']['kind'] ?? 'none') !== 'none') {
            $due = $this->dates->resolve($item['due_date'], $user->timezone, $now);
            if ($due !== null && $due->greaterThanOrEqualTo($date) && $due->lessThanOrEqualTo($today->addDays(1100))) {
                $dueOn = $due->format('Y-m-d');
            }
        }

        $confidence = max(0.0, min(1.0, (float) ($item['confidence'] ?? 0)));
        $factors = [];
        $categoryId = $categoryName = null;
        $counterpartyId = $counterpartyName = null;
        $newPerson = false;
        $shares = [];

        // --- who
        if ($person) {
            $named = trim((string) ($item['counterparty'] ?? ''));
            if ($named === '' || in_array('counterparty', $item['missing_fields'] ?? [], true)) {
                return Decision::clarify($i, 'counterparty_missing', match ($type) {
                    TransactionType::Lend => 'Who did you lend it to?',
                    TransactionType::Borrow => 'Who did you borrow it from?',
                    TransactionType::RepaymentIn => 'Who paid you back?',
                    default => 'Who did you pay back?',
                }, 0.0, 'counterparty', $item);
            }
            [$counterpartyId, $counterpartyName, $newPerson, $err] = $this->person($user, $named, $i);
            if ($err) {
                return $err;
            }

            if (in_array($type, [TransactionType::RepaymentIn, TransactionType::RepaymentOut], true)) {
                $direction = $type === TransactionType::RepaymentIn ? DebtService::RECEIVABLE : DebtService::PAYABLE;
                $open = $counterpartyId ? $this->debts->openMinor($user->id, $counterpartyId, $direction) : 0;
                $owes = $direction === DebtService::RECEIVABLE ? "{$counterpartyName} doesn't owe you anything I know of" : "you don't owe {$counterpartyName} anything I know of";
                if ($open === 0) {
                    return Decision::clarify($i, 'nothing_owed', "I have no record that {$owes}. Nothing was recorded. If it was a new loan, say \"lent\" or \"borrowed\"; if it was an expense, say what it was for.");
                }
                if ($money->minor > $open) {
                    return Decision::clarify($i, 'repayment_exceeds', ($direction === DebtService::RECEIVABLE ? "{$counterpartyName} owes you " : "You owe {$counterpartyName} ").Money::ofMinor($open, $currency)->format()
                        .", which is less than {$money->format()}. Nothing was recorded. Send the right amount, or tell me what the rest was.");
                }
            }
        }

        // --- category and shares for a split
        if ($split) {
            $named = trim((string) ($item['category'] ?? ''));
            if ($named === '') {
                return Decision::clarify($i, 'category_missing', "{$money->format()} for what?", 0.0, 'category', $item);
            }
            $r = $this->resolver->resolve($user->id, EntityType::Category, $named, CategoryKind::Expense);
            if ($r->isResolved()) {
                $categoryId = $r->entityId;
                $categoryName = $r->name;
                $factors[] = $r->matchType === 'fuzzy' ? 0.8 : 1.0;
            } elseif ($r->isAmbiguous()) {
                return Decision::clarify($i, 'category_ambiguous', 'Which category did you mean for "'.$named.'": '.$this->orList(array_column($r->candidates, 'name')).'?', 0.0, 'category', $item);
            } else {
                return Decision::clarify($i, 'category_unknown', "I don't have a category called \"{$named}\". Which of your categories fits? (for example ".$this->sampleCategories($user, CategoryKind::Expense).')', 0.0, 'category', $item);
            }

            [$shares, $err] = $this->shares($user, $item, $money, $text, $i);
            if ($err) {
                return $err;
            }
        }

        // --- accounts
        $to = null;
        if ($type === TransactionType::CcPayment) {
            [$account, $err] = $this->account($user, TransactionType::Income, $item['account'] ?? null, null, $i, 'from', $item);
            if ($err) {
                return $err;
            }
            [$to, $err] = $this->card($user, $item['to_account'] ?? null, $i, $item);
            if ($err) {
                return $err;
            }
        } else {
            // a split is paid like an expense (cards allowed); the others move money in/out of an own asset account
            [$account, $err] = $this->account($user, $split ? TransactionType::Expense : TransactionType::Income, $item['account'] ?? null, $item['payment_method'] ?? null, $i, 'from', $item);
            if ($err) {
                return $err;
            }
        }

        $entity = $factors === [] ? 1.0 : min($factors);
        $score = 0.60 * $confidence + 0.25 * ($verified === true ? 1.0 : 0.7) + 0.15 * $entity;
        $description = $this->description($item['description'] ?? null, null, match ($type) {
            TransactionType::Lend => "Lent to {$counterpartyName}", TransactionType::Borrow => "Borrowed from {$counterpartyName}",
            TransactionType::RepaymentIn => "Repayment from {$counterpartyName}", TransactionType::RepaymentOut => "Repayment to {$counterpartyName}",
            TransactionType::CcPayment => 'Card payment', default => $categoryName,
        });
        $method = isset($item['payment_method']) ? PaymentMethod::tryFrom((string) $item['payment_method']) : null;

        $posting = new ProposedPosting(
            type: $type, money: $money, occurredOn: $date->format('Y-m-d'),
            accountId: $account->id, accountName: $account->name, toAccountId: $to?->id, toAccountName: $to?->name,
            categoryId: $categoryId, categoryName: $categoryName, merchantId: null, description: $description,
            paymentMethod: $method, confidence: $confidence, isToday: $date->isSameDay($today),
            counterpartyId: $counterpartyId, counterpartyName: $counterpartyName, newPerson: $newPerson, dueOn: $dueOn, shares: $shares,
        );

        if ($score < (float) config('ai.risk.confirm_min_score')) {
            return Decision::clarify($i, 'low_confidence', 'I\'m not sure I understood that. Nothing was recorded. Could you say it again with a bit more detail?', $score);
        }

        $anyNew = $newPerson || in_array(true, array_map(fn ($s) => $s['id'] === null, $shares), true);
        $reason = match (true) {
            $split => 'split',
            $type === TransactionType::CcPayment => 'card_payment',
            in_array($type, [TransactionType::Lend, TransactionType::Borrow], true) => 'person_loan', // money to/from a person: always one tap
            $anyNew => 'new_person',
            $money->minor > (int) config('ai.risk.confirm_above_minor') => 'large_amount',
            $score < (float) config('ai.risk.auto_commit_min_score') => 'low_confidence',
            default => null,
        };
        if ($reason !== null) {
            $prefix = ($anyNew ? 'I don\'t have '.($newPerson ? $counterpartyName : 'some of these people').' in your list yet; I\'ll add them. ' : '').match ($reason) {
                'large_amount' => "That's a large amount. ",
                'low_confidence' => 'I think you meant this. ',
                default => '',
            };

            return Decision::confirm($i, $reason, $prefix.'Record '.$posting->describe().'?', $posting, $score);
        }

        return Decision::record($i, $posting, $score);
    }

    /**
     * People only ever match exactly (an alias or the full name); a near match is a question, an unknown name is a new person.
     *
     * @return array{0: ?string, 1: string, 2: bool, 3: ?Decision} id, display name, is new, error
     */
    private function person(User $user, string $named, int $i): array
    {
        $r = $this->resolver->resolve($user->id, EntityType::Counterparty, $named);
        if ($r->isResolved() && $r->matchType !== 'fuzzy') {
            return [$r->entityId, (string) $r->name, false, null];
        }
        if ($r->isResolved() || $r->isAmbiguous()) {
            $names = $r->isResolved() ? [$r->name] : array_column($r->candidates, 'name');

            return [null, $named, false, Decision::clarify($i, 'person_unsure', 'Did you mean '.$this->orList($names).' by "'.$named.'"? If it is someone new, send their full name.')];
        }

        $clean = trim(preg_replace('/\s+/u', ' ', $named) ?? '');
        if (mb_strlen($clean, 'UTF-8') > 60 || preg_match('/\d/', $clean)) {
            return [null, $named, false, Decision::clarify($i, 'person_invalid', 'I couldn\'t read that name. Who is it?')];
        }

        return [null, mb_convert_case($clean, MB_CASE_TITLE, 'UTF-8'), true, null];
    }

    /**
     * @return array{0: list<array{id: ?string, name: string, minor: int}>, 1: ?Decision}
     */
    private function shares(User $user, array $item, Money $total, string $text, int $i): array
    {
        $raw = array_values(array_filter((array) ($item['participants'] ?? []), fn ($p) => is_array($p)));
        $people = [];
        foreach ($raw as $p) {
            $name = trim((string) ($p['name'] ?? ''));
            if ($name === '' || in_array(mb_strtolower($name, 'UTF-8'), ['me', 'i', 'myself', 'main', 'mai', 'mujhe'], true)) {
                continue; // the user's own share is whatever remains
            }
            [$id, $display, , $err] = $this->person($user, $name, $i);
            if ($err) {
                return [[], $err];
            }
            $key = mb_strtolower($display, 'UTF-8');
            if (isset($people[$key])) {
                return [[], Decision::clarify($i, 'split_duplicate', "{$display} appears twice in the split. Please send it again.")];
            }
            $people[$key] = ['id' => $id, 'name' => $display, 'amount' => $p['amount'] ?? null];
        }

        if ($people === []) {
            return [[], Decision::clarify($i, 'split_people_missing', 'Who did you split it with? For example "dinner 2400 split with Rahul and Amit".')];
        }
        if (count($people) > 10) {
            return [[], Decision::clarify($i, 'split_too_many', 'That is a lot of people; I can split between up to 10 others at a time.')];
        }

        $given = array_filter($people, fn ($p) => $p['amount'] !== null && $p['amount'] !== '');
        if ($given === []) { // equal split including the user; any odd paisa goes to the payer first
            $parts = $total->splitEqually(count($people) + 1);
            array_shift($parts);
            $shares = [];
            foreach (array_values($people) as $n => $p) {
                $shares[] = ['id' => $p['id'], 'name' => $p['name'], 'minor' => $parts[$n]->minor];
            }

            return [$shares, null];
        }
        if (count($given) !== count($people)) {
            return [[], Decision::clarify($i, 'split_mixed', 'I need either an amount for everyone you split with, or none (for an equal split). Please send it again.')];
        }

        $shares = [];
        $sum = 0;
        foreach ($people as $p) {
            try {
                $m = Money::parse((string) $p['amount'], $total->currency);
            } catch (InvalidArgumentException) {
                return [[], Decision::clarify($i, 'amount_invalid', "I couldn't read {$p['name']}'s share. Please send it again.")];
            }
            if (! $m->isPositive() || $this->amounts->matches((string) $p['amount'], $text) === false) {
                return [[], Decision::clarify($i, 'share_not_in_text', "I can't find {$p['name']}'s share in your message. Please send it again.")];
            }
            $sum += $m->minor;
            $shares[] = ['id' => $p['id'], 'name' => $p['name'], 'minor' => $m->minor];
        }
        if ($sum > $total->minor) {
            return [[], Decision::clarify($i, 'shares_exceed_total', 'The shares add up to more than '.$total->format().'. Nothing was recorded. Please check the amounts and send it again.')];
        }

        return [$shares, null];
    }

    /** The credit card a bill payment is for. Exact name or alias only; the only card is used when none is named. */
    private function card(User $user, ?string $named, int $i, array $item): array
    {
        $cards = LedgerAccount::where('user_id', $user->id)->where('subtype', AccountSubtype::CreditCard->value)->where('status', 'active')->get();
        if ($cards->isEmpty()) {
            return [null, Decision::clarify($i, 'no_card', 'You have no credit card set up yet, so I can\'t record a card payment. Nothing was recorded.')];
        }
        $named = trim((string) $named);
        if ($named === '') {
            return $cards->count() === 1
                ? [$cards->first(), null]
                : [null, Decision::clarify($i, 'card_missing', 'Which card did you pay? Your cards: '.$cards->pluck('name')->implode(', ').'.', 0.0, 'to_account', $item)];
        }
        $r = $this->resolver->resolve($user->id, EntityType::Account, $named);
        $card = $r->isResolved() && $r->matchType !== 'fuzzy' ? $cards->firstWhere('id', $r->entityId) : null;

        return $card ? [$card, null]
            : [null, Decision::clarify($i, 'card_unknown', "I don't have a card called \"{$named}\". Your cards: ".$cards->pluck('name')->implode(', ').'.', 0.0, 'to_account', $item)];
    }

    private function decideRecord(User $user, array $item, int $i, string $text, CarbonImmutable $now): Decision
    {
        $typeName = $item['event_type'] ?? null;
        if ($typeName === null) {
            return Decision::clarify($i, 'event_type_missing', 'I couldn\'t tell what kind of entry this is (an expense, income or a transfer between your own accounts). Could you rephrase it, e.g. "spent 250 on vegetables"?');
        }
        if ($typeName === 'opening_balance') { // same thing as create_account, however the model chose to phrase it
            return $this->decideAccount($user, $item, $i, $text);
        }
        if ($typeName === 'emi_payment') {
            return $this->decideEmi($user, $item, $i, $text, $now);
        }
        if (isset(self::DEBT_TYPES[$typeName])) {
            return $this->decideDebt($user, $item, $i, $text, $now, self::DEBT_TYPES[$typeName]);
        }
        if (! in_array($typeName, self::SUPPORTED, true)) {
            $label = self::LABEL[$typeName] ?? $typeName;

            return Decision::unsupported($i, 'event_type_not_available',
                "That looks like {$label}. I can't record that kind of entry yet, so nothing was recorded"
                .($typeName === 'credit_card_payment' ? ' (and it is deliberately not counted as an expense).' : '.'));
        }
        $type = TransactionType::from($typeName);

        // --- amount: parse, bound, and cross-check against what the user actually typed
        if (($item['amount'] ?? null) === null || in_array('amount', $item['missing_fields'] ?? [], true)) {
            return Decision::clarify($i, 'amount_missing', 'How much was it?', 0.0, 'amount', $item);
        }
        $currency = strtoupper(trim((string) ($item['currency'] ?? ''))) ?: $user->base_currency;
        if ($currency !== $user->base_currency) {
            return Decision::unsupported($i, 'currency_not_supported', "I only record amounts in {$user->base_currency} for now, so nothing was recorded.");
        }
        try {
            $money = Money::parse((string) $item['amount'], $currency);
        } catch (InvalidArgumentException) {
            return Decision::clarify($i, 'amount_invalid', 'I couldn\'t read the amount. How much was it?', 0.0, 'amount', $item);
        }
        if (! $money->isPositive()) {
            return Decision::clarify($i, 'amount_not_positive', 'The amount must be more than zero. How much was it?', 0.0, 'amount', $item);
        }
        if ($money->minor > (int) config('ai.risk.max_amount_minor')) {
            return Decision::clarify($i, 'amount_too_large', 'That amount looks too large to be right. Please check it and send it again.');
        }
        $verified = $this->amounts->matches((string) $item['amount'], $text);
        if ($verified === false) {
            return Decision::clarify($i, 'amount_not_in_text', "I read {$money->format()}, but I can't find that amount in your message. How much was it?", 0.0, 'amount', $item);
        }

        // --- date
        $date = $this->dates->resolve($item['date'] ?? ['kind' => 'none'], $user->timezone, $now);
        $today = $now->setTimezone($user->timezone)->startOfDay();
        if ($date === null || $date->greaterThan($today->addDays((int) config('ai.risk.max_future_days')))
            || $date->lessThan($today->subDays((int) config('ai.risk.max_past_days')))) {
            return Decision::clarify($i, 'date_invalid', 'I couldn\'t work out the date. Which date was it?');
        }

        // --- entities
        $factors = [];
        $categoryId = $categoryName = null;
        $merchantId = null;
        $merchant = null;

        if (! empty($item['merchant'])) {
            $r = $this->resolver->resolve($user->id, EntityType::Merchant, $item['merchant']);
            if ($r->isResolved()) {
                $merchantId = $r->entityId;
                $merchant = Merchant::find($merchantId);
            }
        }

        if ($type !== TransactionType::Transfer) {
            $kind = $type === TransactionType::Income ? CategoryKind::Income : CategoryKind::Expense;
            $named = trim((string) ($item['category'] ?? ''));

            if ($named !== '') {
                $r = $this->resolver->resolve($user->id, EntityType::Category, $named, $kind);
                if ($r->isResolved()) {
                    $categoryId = $r->entityId;
                    $categoryName = $r->name;
                    $factors[] = $r->matchType === 'fuzzy' ? 0.8 : 1.0;
                } elseif ($r->isAmbiguous()) {
                    return Decision::clarify($i, 'category_ambiguous', 'Which category did you mean for "'.$named.'": '.$this->orList(array_column($r->candidates, 'name')).'?', 0.0, 'category', $item);
                } else {
                    return Decision::clarify($i, 'category_unknown', "I don't have a category called \"{$named}\". Which of your categories fits? (for example ".$this->sampleCategories($user, $kind).')', 0.0, 'category', $item);
                }
            } elseif ($merchant && $merchant->default_category_id && ($c = Category::where('user_id', $user->id)->where('kind', $kind->value)->find($merchant->default_category_id))) {
                $categoryId = $c->id; // the user's own merchant -> category mapping
                $categoryName = $c->name;
                $factors[] = 0.9;
            } else {
                return Decision::clarify($i, 'category_missing', $type === TransactionType::Income
                    ? "{$money->format()} received for what? (for example salary, freelance, interest)"
                    : "{$money->format()} paid for what?", 0.0, 'category', $item);
            }
        }

        [$account, $err] = $this->account($user, $type, $item['account'] ?? null, $item['payment_method'] ?? null, $i, 'from', $item);
        if ($err) {
            return $err;
        }
        $to = null;
        if ($type === TransactionType::Transfer) {
            [$to, $err] = $this->account($user, $type, $item['to_account'] ?? null, null, $i, 'to', $item);
            if ($err) {
                return $err;
            }
            if ($account->id === $to->id) {
                return Decision::clarify($i, 'transfer_same_account', 'The two accounts of a transfer must be different. Which accounts do you mean?');
            }
        }

        // --- build the validated proposal, then decide how much trust it earns
        $confidence = max(0.0, min(1.0, (float) ($item['confidence'] ?? 0)));
        $entity = $factors === [] ? 1.0 : min($factors);
        $score = 0.60 * $confidence + 0.25 * ($verified === true ? 1.0 : 0.7) + 0.15 * $entity;

        $paymentMethod = isset($item['payment_method']) ? PaymentMethod::tryFrom((string) $item['payment_method']) : null;
        $posting = new ProposedPosting(
            type: $type, money: $money, occurredOn: $date->format('Y-m-d'),
            accountId: $account->id, accountName: $account->name,
            toAccountId: $to?->id, toAccountName: $to?->name,
            categoryId: $categoryId, categoryName: $categoryName, merchantId: $merchantId,
            description: $this->description($item['description'] ?? null, $merchant?->name, $categoryName),
            paymentMethod: $paymentMethod, confidence: $confidence, isToday: $date->isSameDay($today),
        );

        // Too unsure even to ask "is this right?": ask the user to rephrase.
        if ($score < (float) config('ai.risk.confirm_min_score')) {
            return Decision::clarify($i, 'low_confidence', 'I\'m not sure I understood that ('.$this->summary($type, $money, $categoryName).'). Nothing was recorded. Could you say it again with a bit more detail?', $score);
        }

        // Some things always need a tap: moving money between accounts, big amounts, and shaky reads.
        $reason = match (true) {
            $type === TransactionType::Transfer => 'transfer',
            $type === TransactionType::Expense && $money->minor > (int) config('ai.risk.confirm_above_minor') => 'large_amount',
            $score < (float) config('ai.risk.auto_commit_min_score') => 'low_confidence',
            default => null,
        };
        if ($reason !== null) {
            $prefix = match ($reason) {
                'large_amount' => "That's a large amount. ",
                'low_confidence' => 'I think you meant this. ',
                default => '',
            };

            return Decision::confirm($i, $reason, $prefix.'Record '.$posting->describe().'?', $posting, $score);
        }

        return Decision::record($i, $posting, $score);
    }

    /**
     * Money moves between accounts, so accounts are never auto-picked from a fuzzy match: only an exact
     * name or alias resolves. Unnamed -> a sensible default (never for transfers).
     *
     * @return array{0: ?LedgerAccount, 1: ?Decision}
     */
    private function account(User $user, TransactionType $type, ?string $named, ?string $method, int $i, string $role, array $item): array
    {
        $named = trim((string) $named);
        $awaiting = $role === 'to' ? 'to_account' : 'account';

        if ($named !== '') {
            $r = $this->resolver->resolve($user->id, EntityType::Account, $named);
            if ($r->isResolved() && $r->matchType !== 'fuzzy') {
                $account = LedgerAccount::find($r->entityId);
            } elseif ($r->isResolved() || $r->isAmbiguous()) {
                $names = $r->isResolved() ? [$r->name] : array_column($r->candidates, 'name');

                return [null, Decision::clarify($i, 'account_unsure', 'Which account did you mean by "'.$named.'": '.$this->orList($names).'?', 0.0, $awaiting, $item)];
            } else {
                return [null, Decision::clarify($i, 'account_unknown', "I don't have an account called \"{$named}\". Your accounts: ".$this->accountNames($user).'.', 0.0, $awaiting, $item)];
            }
        } elseif ($type === TransactionType::Transfer && ! ($role === 'from' && $this->toIsGoal($user, $item))) {
            return [null, Decision::clarify($i, 'transfer_accounts_missing', 'Which accounts is this transfer between? For example "transfer 1000 from SBI to HDFC".')];
        } else {
            $account = null;
            if ($method === 'credit_card') {
                $cards = LedgerAccount::where('user_id', $user->id)->where('subtype', AccountSubtype::CreditCard->value)->where('status', 'active')->get();
                $account = $cards->count() === 1 ? $cards->first() : null;
            }
            $defaultId = $user->settings?->default_account_id;
            $account ??= $defaultId ? LedgerAccount::where('user_id', $user->id)->where('status', 'active')->find($defaultId) : null;
            if (! $account) {
                return [null, Decision::clarify($i, 'account_missing', 'Which account? Your accounts: '.$this->accountNames($user).'.', 0.0, $awaiting, $item)];
            }
        }

        $ok = match (true) {
            $type === TransactionType::Expense => $account->subtype->isSpendable(),
            default => $account->subtype->isOwnedAsset(),
        };
        if (! $ok) {
            return [null, Decision::clarify($i, 'account_not_suitable', "I can't use \"{$account->name}\" for that. Which account should it be? Your accounts: ".$this->accountNames($user).'.', 0.0, $awaiting, $item)];
        }

        return [$account, null];
    }

    /** "transfer 4000 to bike": saving towards a goal needs no source account; the default account is used. */
    private function toIsGoal(User $user, array $item): bool
    {
        $named = trim((string) ($item['to_account'] ?? ''));
        if ($named === '') {
            return false;
        }
        $r = $this->resolver->resolve($user->id, EntityType::Account, $named);

        return $r->isResolved() && $r->matchType !== 'fuzzy'
            && LedgerAccount::where('user_id', $user->id)->whereKey($r->entityId)->where('subtype', AccountSubtype::Goal->value)->exists();
    }

    private function description(?string $model, ?string $merchant, ?string $category): string
    {
        $d = trim(preg_replace('/\s+/u', ' ', (string) $model) ?? '');
        $d = $d !== '' ? $d : (string) ($merchant ?? $category ?? '');

        return mb_substr($d, 0, 120, 'UTF-8');
    }

    private function summary(TransactionType $type, Money $money, ?string $category): string
    {
        return $money->format().' '.$type->value.($category ? " for {$category}" : '');
    }

    private function didNotUnderstand(): string
    {
        return 'I couldn\'t understand that. Try something like "spent 250 on vegetables" or "salary 45000". Send "help" for more examples.';
    }

    /** @param list<string> $names */
    private function orList(array $names): string
    {
        $names = array_values($names);

        return count($names) <= 1 ? ($names[0] ?? '') : implode(', ', array_slice($names, 0, -1)).' or '.end($names);
    }

    private function accountNames(User $user): string
    {
        return LedgerAccount::where('user_id', $user->id)->where('status', 'active')
            ->whereNotIn('subtype', ['system', 'receivable', 'payable'])->orderBy('name')->pluck('name')->implode(', ') ?: 'none yet';
    }

    private function sampleCategories(User $user, CategoryKind $kind): string
    {
        return Category::where('user_id', $user->id)->where('kind', $kind->value)->whereNull('parent_id')
            ->orderBy('sort')->limit(4)->pluck('name')->implode(', ');
    }
}
