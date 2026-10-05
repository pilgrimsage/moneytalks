<?php

namespace App\Services\Interpretation;

use App\Enums\CategoryKind;
use App\Enums\EntityType;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * "Actually that was 600, not 500." Turns the model's correction item plus the transaction it points at into
 * a validated REPLACEMENT proposal, using the same deterministic checks as a new transaction. The ledger
 * applies it as reverse + repost; nothing is edited in place.
 */
class CorrectionPlanner
{
    public function __construct(
        private readonly TransactionLocator $locator,
        private readonly EntityResolver $resolver,
        private readonly AmountNormalizer $amounts,
        private readonly DateResolver $dates,
    ) {}

    /** @return Decision CONFIRM (carrying the replacement and the original id) or CLARIFY */
    public function plan(User $user, array $item, LedgerTransaction $tx, string $text, CarbonImmutable $now, int $index = 0): Decision
    {
        $f = $this->locator->facts($tx);
        $type = $f['type'];

        if (! in_array($type, [TransactionType::Expense, TransactionType::Income, TransactionType::Transfer], true)) {
            return Decision::clarify($index, 'not_correctable', 'I can\'t change that kind of entry. If it is wrong, say "undo" and record it again.');
        }

        $money = Money::ofMinor($f['minor'], $f['currency']);
        $date = $f['occurred_on'];
        $category = ['id' => $f['category_id'], 'name' => $f['category_name']];
        $account = ['id' => $f['account_id'], 'name' => $f['account_name']];
        $to = ['id' => $f['to_account_id'], 'name' => $f['to_account_name']];
        $method = $f['payment_method'] ? PaymentMethod::from($f['payment_method']) : null;
        $description = $f['description'];
        $changed = [];

        if (($item['amount'] ?? null) !== null) {
            try {
                $new = Money::parse((string) $item['amount'], $user->base_currency);
            } catch (InvalidArgumentException) {
                return Decision::clarify($index, 'amount_invalid', 'I couldn\'t read the new amount. What should it be?');
            }
            if (! $new->isPositive() || $new->minor > (int) config('ai.risk.max_amount_minor')) {
                return Decision::clarify($index, 'amount_invalid', 'That doesn\'t look like a valid amount. What should it be?');
            }
            if ($this->amounts->matches((string) $item['amount'], $text) === false) {
                return Decision::clarify($index, 'amount_not_in_text', "I read {$new->format()}, but I can't find that amount in your message. What should the new amount be?");
            }
            if (! $new->equals($money)) {
                $money = $new;
                $changed[] = 'amount';
            }
        }

        if (($item['date']['kind'] ?? 'none') !== 'none') {
            $resolved = $this->dates->resolve($item['date'], $user->timezone, $now);
            $today = $now->setTimezone($user->timezone)->startOfDay();
            if (! $resolved || $resolved->greaterThan($today->addDays((int) config('ai.risk.max_future_days')))
                || $resolved->lessThan($today->subDays((int) config('ai.risk.max_past_days')))) {
                return Decision::clarify($index, 'date_invalid', 'I couldn\'t work out the new date. Which date should it be?');
            }
            if ($resolved->format('Y-m-d') !== $date) {
                $date = $resolved->format('Y-m-d');
                $changed[] = 'date';
            }
        }

        if (! empty($item['category']) && $type !== TransactionType::Transfer) {
            $kind = $type === TransactionType::Income ? CategoryKind::Income : CategoryKind::Expense;
            $r = $this->resolver->resolve($user->id, EntityType::Category, $item['category'], $kind);
            if (! $r->isResolved()) {
                return Decision::clarify($index, 'category_unknown', "I don't have a category called \"{$item['category']}\". Which category should it be?");
            }
            if ($r->entityId !== $category['id']) {
                $category = ['id' => $r->entityId, 'name' => $r->name];
                $changed[] = 'category';
            }
        }

        foreach ([['account', &$account, 'from'], ['to_account', &$to, 'to']] as [$field, &$slot, $role]) {
            if (empty($item[$field]) || ($field === 'to_account' && $type !== TransactionType::Transfer)) {
                continue;
            }
            $r = $this->resolver->resolve($user->id, EntityType::Account, $item[$field]);
            if (! $r->isResolved() || $r->matchType === 'fuzzy') {
                return Decision::clarify($index, 'account_unknown', "I don't have an account I can match to \"{$item[$field]}\". Which account should it be?");
            }
            $acct = LedgerAccount::find($r->entityId);
            $suitable = $type === TransactionType::Expense ? $acct->subtype->isSpendable() : $acct->subtype->isOwnedAsset();
            if (! $suitable) {
                return Decision::clarify($index, 'account_not_suitable', "I can't use \"{$acct->name}\" for that. Which account should it be?");
            }
            if ($acct->id !== $slot['id']) {
                $slot = ['id' => $acct->id, 'name' => $acct->name];
                $changed[] = $field;
            }
        }
        unset($slot);

        if (! empty($item['payment_method']) && ($pm = PaymentMethod::tryFrom((string) $item['payment_method'])) && $pm !== $method) {
            $method = $pm;
            $changed[] = 'payment_method';
        }
        if (! empty($item['description']) && trim((string) $item['description']) !== (string) $description) {
            $description = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $item['description']) ?? ''), 0, 120, 'UTF-8');
            $changed[] = 'description';
        }

        if ($changed === []) {
            return Decision::clarify($index, 'nothing_to_change', 'What should I change about it? For example "actually it was 600" or "that was yesterday".');
        }
        if ($type === TransactionType::Transfer && $account['id'] === $to['id']) {
            return Decision::clarify($index, 'transfer_same_account', 'The two accounts of a transfer must be different.');
        }

        $posting = new ProposedPosting(
            type: $type, money: $money, occurredOn: $date,
            accountId: $account['id'], accountName: $account['name'], toAccountId: $to['id'], toAccountName: $to['name'],
            categoryId: $category['id'], categoryName: $category['name'], merchantId: $f['merchant_id'],
            description: (string) $description, paymentMethod: $method, confidence: 1.0,
            isToday: $date === $now->setTimezone($user->timezone)->format('Y-m-d'),
        );

        $message = 'Change '.$this->locator->describe($tx)."\nto ".$posting->describe().'?';

        return new Decision(Decision::CONFIRM, $index, 'correction', $message, $posting, 1.0, null, ['correct_tx_id' => $tx->id, 'changed' => $changed]);
    }
}
