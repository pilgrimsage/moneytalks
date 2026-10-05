<?php

namespace App\Services\Loans;

use App\Domain\Ledger\AccountService;
use App\Domain\Ledger\DTO\PostingCommand;
use App\Domain\Ledger\LedgerService;
use App\Enums\AccountSubtype;
use App\Enums\TransactionSource;
use App\Enums\TransactionType;
use App\Models\Category;
use App\Models\LedgerAccount;
use App\Models\Loan;
use App\Models\User;
use App\Models\UserAlias;
use App\Services\Interpretation\AmountNormalizer;
use App\Support\Money;
use App\Support\Text;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Loans repaid by EMI. The outstanding balance is the loan account's ledger balance. Interest each month is
 * outstanding x annual rate / 12, rounded half-up in whole minor units (integer maths); the EMI itself, when the
 * user does not state it, is an ESTIMATE from the standard formula (lenders' schedules can differ slightly).
 */
class LoanService
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly LedgerService $ledger,
        private readonly AmountNormalizer $amounts,
    ) {}

    /** Monthly interest on an outstanding balance at an annual rate in basis points, rounded half up. */
    public static function interestFor(int $outstandingMinor, int $rateBp): int
    {
        return intdiv($outstandingMinor * $rateBp + 60000, 120000);
    }

    /** The standard EMI estimate. Floats are used only for the (1+r)^n factor; the result is rounded to whole minor units. */
    public static function emiFor(int $principalMinor, int $rateBp, int $months): int
    {
        if ($rateBp === 0) {
            return intdiv($principalMinor + $months - 1, $months);
        }
        $r = $rateBp / 120000;
        $f = (1 + $r) ** $months;

        return (int) round($principalMinor * $r * $f / ($f - 1));
    }

    /** Months until paid off if the EMI is paid every month (capped at 1200). */
    public static function monthsLeft(int $outstandingMinor, int $rateBp, int $emiMinor): ?int
    {
        for ($m = 1; $m <= 1200; $m++) {
            $interest = self::interestFor($outstandingMinor, $rateBp);
            if ($emiMinor <= $interest) {
                return null; // the EMI never pays the loan off
            }
            $outstandingMinor -= $emiMinor - $interest;
            if ($outstandingMinor <= 0) {
                return $m;
            }
        }

        return null;
    }

    /** Handle a create_loan item. The amount is what is OUTSTANDING now; tenure is the months remaining. */
    public function create(User $user, array $item, string $text, CarbonImmutable $now): string
    {
        $name = $this->cleanName((string) ($item['description'] ?? ''));
        if ($name === '') {
            return 'What is the loan for? For example "bike loan, 120000 outstanding at 10% for 24 months".';
        }
        if ($this->find($user, $name)) {
            return "You already track a loan called {$name}.";
        }
        if (($item['amount'] ?? null) === null) {
            return "How much is still outstanding on the {$name} loan?";
        }
        try {
            $outstanding = Money::parse((string) $item['amount'], $user->base_currency);
        } catch (InvalidArgumentException) {
            return 'I couldn\'t read the loan amount. How much is outstanding?';
        }
        if (! $outstanding->isPositive() || $outstanding->minor > (int) config('ai.risk.max_amount_minor')) {
            return 'That amount does not look right. How much is outstanding?';
        }
        if ($this->amounts->matches((string) $item['amount'], $text) !== true) {
            return "I read {$outstanding->format()}, but I can't find that amount in your message. How much is outstanding?";
        }

        $rateBp = $this->rateBp($item['interest_rate'] ?? null);
        if ($rateBp === null) {
            return 'What is the yearly interest rate? For example "at 10.5%". Use 0 if there is no interest.';
        }
        $months = (int) ($item['tenure_months'] ?? 0);
        if ($months < 1 || $months > 600) {
            return 'How many months are left on the loan? For example "for 24 months".';
        }

        $emi = null;
        if (! empty($item['emi_amount'])) {
            try {
                $e = Money::parse((string) $item['emi_amount'], $user->base_currency);
            } catch (InvalidArgumentException) {
                return 'I couldn\'t read the EMI amount.';
            }
            if (! $e->isPositive() || $this->amounts->matches((string) $item['emi_amount'], $text) !== true) {
                return "I can't find that EMI amount in your message. What is the EMI?";
            }
            $emi = $e->minor;
        }
        $estimated = $emi === null;
        $emi ??= self::emiFor($outstanding->minor, $rateBp, $months);
        if ($emi <= self::interestFor($outstanding->minor, $rateBp)) {
            return 'That EMI would not even cover the interest, so the loan would never be paid off. Please check the numbers.';
        }

        $account = $this->accounts->create($user, 'Loan: '.$name, AccountSubtype::Loan);
        $loan = Loan::create([
            'user_id' => $user->id, 'account_id' => $account->id, 'name' => $name, 'rate_bp' => $rateBp,
            'emi_minor' => $emi, 'opening_minor' => $outstanding->minor, 'started_on' => $now->setTimezone($user->timezone)->format('Y-m-d'), 'status' => 'active',
        ]);
        $this->ledger->post(new PostingCommand(
            userId: $user->id, type: TransactionType::OpeningBalance, money: $outstanding, occurredOn: $loan->started_on->format('Y-m-d'),
            accountId: $account->id, idempotencyKey: "loan-open:{$loan->id}", description: "Opening balance: {$name} loan", source: TransactionSource::WhatsappText,
        ));
        foreach (array_unique([Text::normalize($name), Text::normalize($name.' loan'), Text::normalize($name.' emi')]) as $alias) {
            UserAlias::firstOrCreate(['user_id' => $user->id, 'entity_type' => 'account', 'alias' => $alias], ['entity_id' => $account->id, 'source' => 'user']);
        }

        return "🏦 Tracking the *{$name}* loan: {$outstanding->format()} outstanding at ".$this->rateLabel($rateBp).', EMI '.Money::ofMinor($emi, $user->base_currency)->format()
            .($estimated ? ' (my estimate; tell me the real EMI if it differs)' : '').".\nWhen you pay, say \"paid {$name} EMI\" and I'll split interest and principal.";
    }

    /**
     * Work out an EMI payment: the total (stated, else the loan's EMI), the interest part and the principal part.
     *
     * @return array{loan: Loan, account: LedgerAccount, total: int, interest: int, principal: int, outstanding: int}|string a refusal/question as text
     */
    public function plan(User $user, ?string $nameOrNull, ?Money $stated): array|string
    {
        $loans = Loan::where('user_id', $user->id)->where('status', 'active')->get();
        if ($loans->isEmpty()) {
            return 'You are not tracking any loan yet. Tell me about it first, for example "bike loan, 120000 outstanding at 10% for 24 months".';
        }
        $needle = Text::normalize((string) $nameOrNull);
        $loan = $needle === ''
            ? ($loans->count() === 1 ? $loans->first() : null)
            : $loans->first(fn (Loan $l) => Text::normalize($l->name) === $needle || str_contains($needle, Text::normalize($l->name)));
        if (! $loan) {
            return 'Which loan: '.$loans->pluck('name')->implode(', ').'?';
        }

        $account = LedgerAccount::where('user_id', $user->id)->findOrFail($loan->account_id);
        $outstanding = $this->accounts->balance($account)->minor;
        if ($outstanding <= 0) {
            return "The {$loan->name} loan has nothing outstanding.";
        }
        $interest = self::interestFor($outstanding, $loan->rate_bp);
        $total = $stated->minor ?? min($loan->emi_minor, $outstanding + $interest);
        if ($total < $interest) {
            return 'That is less than this month\'s interest ('.Money::ofMinor($interest, $user->base_currency)->format().'), so I wouldn\'t know how to split it. Nothing was recorded.';
        }
        if ($total > $outstanding + $interest) {
            return 'That is more than the whole loan plus interest ('.Money::ofMinor($outstanding + $interest, $user->base_currency)->format().'). Nothing was recorded.';
        }

        return ['loan' => $loan, 'account' => $account, 'total' => $total, 'interest' => $interest, 'principal' => $total - $interest, 'outstanding' => $outstanding];
    }

    /** The user's "Loan Interest" expense category (every user has it from the starter catalog). */
    public function interestCategoryId(User $user): ?string
    {
        return Category::where('user_id', $user->id)->where('kind', 'expense')->where('name', 'Loan Interest')->value('id');
    }

    /** @return list<array{name: string, outstanding: int, rate_bp: int, emi: int, months_left: ?int}> */
    public function status(User $user): array
    {
        $loans = Loan::where('user_id', $user->id)->where('status', 'active')->orderBy('name')->get();
        $balances = $this->accounts->balances($user->id, $loans->pluck('account_id')->all());

        return $loans->map(function (Loan $l) use ($balances) {
            $out = max(0, $balances[$l->account_id]->minor ?? 0);

            return ['name' => $l->name, 'outstanding' => $out, 'rate_bp' => $l->rate_bp, 'emi' => $l->emi_minor, 'months_left' => $out > 0 ? self::monthsLeft($out, $l->rate_bp, $l->emi_minor) : 0];
        })->all();
    }

    /** After an EMI payment: close the loan when nothing is left. */
    public function afterPayment(User $user, string $loanAccountId): ?string
    {
        $loan = Loan::where('user_id', $user->id)->where('account_id', $loanAccountId)->first();
        if (! $loan) {
            return null;
        }
        $left = $this->accounts->balance(LedgerAccount::findOrFail($loanAccountId))->minor;
        if ($left <= 0 && $loan->status === 'active') {
            $loan->update(['status' => 'closed']);

            return "🎉 The *{$loan->name}* loan is fully paid off!";
        }

        return 'Outstanding on '.$loan->name.': '.Money::ofMinor($left, $user->base_currency)->format().'.';
    }

    public function rateLabel(int $bp): string
    {
        return rtrim(rtrim(number_format($bp / 100, 2, '.', ''), '0'), '.').'%';
    }

    /** "10", "10.5", "10.5%" -> basis points; null when unreadable or silly. Pure string maths. */
    private function rateBp(mixed $raw): ?int
    {
        if ($raw === null) {
            return null;
        }
        if (! preg_match('/^\s*(\d{1,2})(?:\.(\d{1,2}))?\s*%?\s*$/', (string) $raw, $m)) {
            return null;
        }
        $bp = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');

        return $bp <= 6000 ? $bp : null;
    }

    private function find(User $user, string $name): ?Loan
    {
        return Loan::where('user_id', $user->id)->whereIn('status', ['active'])->get()->first(fn (Loan $l) => Text::normalize($l->name) === Text::normalize($name));
    }

    private function cleanName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        $name = preg_replace('/\s+(loan|emi)$/iu', '', $name) ?? $name;

        return mb_substr(mb_convert_case(trim($name), MB_CASE_TITLE, 'UTF-8'), 0, 60, 'UTF-8');
    }
}
