<?php

namespace App\Services\Interpretation;

use App\Enums\CategoryKind;
use App\Enums\EntityType;
use App\Models\User;
use App\Support\Text;

/**
 * Completes a pending question WITHOUT another AI call: "groceries using UPI" answers "paid for what?".
 * Deliberately conservative: it only accepts an answer that clearly supplies the missing detail; anything
 * else (a new transaction, a command, chit-chat) returns null and is handled as a brand-new message.
 */
class FollowUpParser
{
    /** Words that name a payment method. Longest phrases first. */
    private const METHODS = [
        'credit card' => 'credit_card', 'debit card' => 'debit_card', 'net banking' => 'net_banking', 'netbanking' => 'net_banking',
        'bank transfer' => 'bank_transfer', 'neft' => 'bank_transfer', 'imps' => 'bank_transfer',
        'upi' => 'upi', 'gpay' => 'upi', 'google pay' => 'upi', 'phonepe' => 'upi', 'paytm' => 'upi',
        'cash' => 'cash', 'wallet' => 'wallet', 'cheque' => 'cheque', 'check' => 'cheque',
    ];

    public function __construct(
        private readonly EntityResolver $resolver,
        private readonly AmountNormalizer $amounts,
    ) {}

    /**
     * @param  array<string, mixed>  $item  the model's item that is waiting for a detail
     * @return array<string, mixed>|null the item with the answer applied, or null if the answer is not an answer
     */
    public function apply(User $user, array $item, string $awaiting, string $answer): ?array
    {
        $answer = trim($answer);
        if ($answer === '' || mb_strlen($answer, 'UTF-8') > 80) {
            return null; // long answers are new messages, not one-word replies
        }

        return match ($awaiting) {
            'amount' => $this->amount($item, $answer),
            'category' => $this->category($user, $item, $answer),
            'account', 'to_account' => $this->account($user, $item, $awaiting, $answer),
            'counterparty' => $this->person($item, $answer),
            default => null,
        };
    }

    private function amount(array $item, string $answer): ?array
    {
        $numbers = $this->amounts->extract($answer);

        return count($numbers) === 1 ? ['amount' => $numbers[0], 'missing_fields' => $this->without($item, 'amount')] + $item : null;
    }

    private function category(User $user, array $item, string $answer): ?array
    {
        // Another number means the user is sending a new transaction, not naming a category.
        if ($this->amounts->extract($answer) !== []) {
            return null;
        }

        [$rest, $method] = $this->splitPaymentMethod($answer);
        $kind = ($item['event_type'] ?? 'expense') === 'income' ? CategoryKind::Income : CategoryKind::Expense;

        $name = $this->bestCategory($user, $rest, $kind);
        if ($name === null) {
            return null;
        }

        $patch = ['category' => $name, 'missing_fields' => $this->without($item, 'category')];
        if ($method !== null && empty($item['payment_method'])) {
            $patch['payment_method'] = $method;
        }

        return $patch + $item;
    }

    /** A person's name: a short answer without digits. Whether they are known is decided by the validator. */
    private function person(array $item, string $answer): ?array
    {
        $name = trim($answer, " \t\n\r.!?");
        if ($name === '' || preg_match('/\d/', $name) || count(explode(' ', $name)) > 3) {
            return null;
        }

        return ['counterparty' => $name, 'missing_fields' => $this->without($item, 'counterparty')] + $item;
    }

    private function account(User $user, array $item, string $field, string $answer): ?array
    {
        if ($this->amounts->extract($answer) !== []) {
            return null;
        }

        $r = $this->resolver->resolve($user->id, EntityType::Account, $answer);
        if (! $r->isResolved() || $r->matchType === 'fuzzy') {
            return null; // accounts are only ever chosen by an exact name or alias
        }

        return [$field => $r->name, 'missing_fields' => $this->without($item, $field)] + $item;
    }

    /** Try the whole answer, then each word group, longest first ("groceries using upi" -> groceries). */
    private function bestCategory(User $user, string $text, CategoryKind $kind): ?string
    {
        $words = explode(' ', Text::normalize($text));
        $words = array_values(array_filter($words, fn ($w) => $w !== '' && ! in_array($w, ['using', 'with', 'via', 'by', 'on', 'for', 'ke', 'ka', 'ki', 'liye', 'se', 'through', 'paid'], true)));
        if ($words === []) {
            return null;
        }

        for ($n = count($words); $n >= 1; $n--) {
            for ($i = 0; $i + $n <= count($words); $i++) {
                $gram = implode(' ', array_slice($words, $i, $n));
                $r = $this->resolver->resolve($user->id, EntityType::Category, $gram, $kind);
                if ($r->isResolved() && $r->matchType !== 'fuzzy') {
                    return $r->name;
                }
            }
        }

        // A typo of the whole (short) answer, e.g. "vegtables".
        $whole = $this->resolver->resolve($user->id, EntityType::Category, implode(' ', $words), $kind);

        return $whole->isResolved() ? $whole->name : null;
    }

    /** @return array{0: string, 1: ?string} the text without the payment-method words, and the method */
    private function splitPaymentMethod(string $answer): array
    {
        $text = ' '.Text::normalize($answer).' ';
        foreach (self::METHODS as $phrase => $method) {
            if (str_contains($text, " {$phrase} ")) {
                return [trim(str_replace(" {$phrase} ", ' ', $text)), $method];
            }
        }

        return [trim($text), null];
    }

    /** @return list<string> */
    private function without(array $item, string $field): array
    {
        return array_values(array_filter((array) ($item['missing_fields'] ?? []), fn ($f) => $f !== $field));
    }
}
