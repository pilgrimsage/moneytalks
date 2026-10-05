<?php

use App\Domain\Ledger\AccountService;
use App\Enums\AccountSubtype;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\Category;
use App\Models\Merchant;
use App\Services\Reporting\Period;
use App\Services\Reporting\ReportQuery;
use App\Services\Reporting\ReportService;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Report numbers are checked against an independent oracle: a plain-PHP ledger of what we posted, never
 * derived from the SQL under test.
 */
beforeEach(function () {
    $this->user = ledgerUser();
    $svc = app(AccountService::class);
    $this->cash = account($this->user, 'Cash');
    $this->bank = $svc->create($this->user, 'HDFC Bank', AccountSubtype::Bank);
    $this->card = $svc->create($this->user, 'HDFC Credit Card', AccountSubtype::CreditCard);
    $this->reports = app(ReportService::class);
    $this->oracle = [];   // [type, minor, date, category (leaf id), merchant, account, method, description]
    $this->post = function (TransactionType $type, string $amount, string $date, ?string $categoryName = null, array $o = []) {
        $account = $o['account'] ?? $this->cash;
        $tx = ledger()->post(command($this->user, $type, $amount, [
            'occurredOn' => $date, 'accountId' => $account->id,
            'categoryId' => $categoryName ? category($this->user, $categoryName)->id : null,
            'merchantId' => $o['merchant'] ?? null, 'paymentMethod' => $o['method'] ?? null, 'description' => $o['description'] ?? null,
            'toAccountId' => $o['to'] ?? null,
        ]))->transaction;
        $this->oracle[$tx->id] = ['type' => $type->value, 'minor' => Money::parse($amount, 'INR')->minor, 'date' => $date,
            'category' => $categoryName ? category($this->user, $categoryName)->id : null, 'merchant' => $o['merchant'] ?? null,
            'account' => $account->name, 'method' => ($o['method'] ?? null)?->value, 'description' => $o['description'] ?? '', 'active' => true];

        return $tx;
    };
    $this->period = fn (string $from, string $to) => new Period(CarbonImmutable::parse($from), CarbonImmutable::parse($to), "{$from}..{$to}");
    $this->sum = fn (string $type, string $from, string $to, ?callable $where = null) => collect($this->oracle)
        ->filter(fn ($t) => $t['active'] && $t['type'] === $type && $t['date'] >= $from && $t['date'] <= $to && ($where === null || $where($t)))->sum('minor');
});

function seedMonth(object $t): void
{
    $uber = Merchant::where('user_id', $t->user->id)->where('name', 'Uber')->first();
    $amazon = Merchant::where('user_id', $t->user->id)->where('name', 'Amazon')->first();
    $E = TransactionType::Expense;
    ($t->post)(TransactionType::Income, '45000', '2026-10-01', 'Salary', ['account' => $t->bank]);
    ($t->post)($E, '250', '2026-10-02', 'Vegetables');
    ($t->post)($E, '1800', '2026-10-02', 'Groceries', ['method' => PaymentMethod::Upi, 'account' => $t->bank]);
    ($t->post)($E, '350', '2026-10-03', 'Cab', ['merchant' => $uber->id, 'description' => 'Office trip']);
    ($t->post)($E, '3000', '2026-10-03', 'Shopping', ['merchant' => $amazon->id, 'account' => $t->card, 'method' => PaymentMethod::CreditCard]);
    ($t->post)($E, '120', '2026-10-04', 'Vegetables', ['description' => '50% off sabji_haul']);
    ($t->post)($E, '700', '2026-09-28', 'Fuel');
    ($t->post)($E, '400', '2026-09-15', 'Restaurant', ['account' => $t->card]);
    ($t->post)(TransactionType::Income, '1000', '2026-09-20', 'Interest', ['account' => $t->bank]);
    ($t->post)(TransactionType::Transfer, '5000', '2026-10-02', null, ['account' => $t->bank, 'to' => $t->cash->id]);
}

describe('totals', function () {
    it('equal the oracle for any period', function (string $from, string $to) {
        seedMonth($this);

        $r = $this->reports->totals($this->user->id, ($this->period)($from, $to));

        expect($r['expense_minor'])->toBe(($this->sum)('expense', $from, $to))->and($r['income_minor'])->toBe(($this->sum)('income', $from, $to));
    })->with([
        'october' => ['2026-10-01', '2026-10-31'], 'september' => ['2026-09-01', '2026-09-30'], 'one day' => ['2026-10-02', '2026-10-02'],
        'spanning months' => ['2026-09-28', '2026-10-02'], 'empty' => ['2025-01-01', '2025-01-31'], 'everything' => ['2000-01-01', '2026-12-31'],
    ]);

    it('are exactly the known figures for October', function () {
        seedMonth($this);

        $r = $this->reports->totals($this->user->id, ($this->period)('2026-10-01', '2026-10-31'));

        expect($r)->toBe(['expense_minor' => 25000 + 180000 + 35000 + 300000 + 12000, 'income_minor' => 4500000, 'expense_count' => 5, 'income_count' => 1]);
    });

    it('never count transfers as income or expense', function () {
        ($this->post)(TransactionType::Transfer, '9999', '2026-10-02', null, ['account' => $this->bank, 'to' => $this->cash->id]);

        expect($this->reports->totals($this->user->id, ($this->period)('2026-10-01', '2026-10-31')))->toMatchArray(['expense_minor' => 0, 'income_minor' => 0]);
    });

    it('net a reversed transaction to exactly zero (and do not count it)', function () {
        $tx = ($this->post)(TransactionType::Expense, '500', '2026-10-02', 'Groceries');
        ($this->post)(TransactionType::Expense, '100', '2026-10-02', 'Groceries');
        ledger()->reverse($this->user->id, $tx->id, 'undo');
        $this->oracle[$tx->id]['active'] = false;

        $r = $this->reports->totals($this->user->id, ($this->period)('2026-10-01', '2026-10-31'));

        expect($r['expense_minor'])->toBe(10000)->and($r['expense_count'])->toBe(1);
    });

    it('show only the corrected amount after a correction', function () {
        $tx = ($this->post)(TransactionType::Expense, '500', '2026-10-02', 'Groceries');
        $new = ledger()->correct($tx->id, command($this->user, TransactionType::Expense, '600', ['occurredOn' => '2026-10-02', 'categoryId' => category($this->user, 'Groceries')->id]), 'fix')->transaction;

        $r = $this->reports->totals($this->user->id, ($this->period)('2026-10-01', '2026-10-31'));

        expect($r['expense_minor'])->toBe(60000)->and($r['expense_count'])->toBe(1)->and($new->corrects_id)->toBe($tx->id);
    });

    it('keep a transaction in the month it belongs to even if it was undone from another month', function () {
        $tx = ($this->post)(TransactionType::Expense, '500', '2026-09-30', 'Groceries');
        ledger()->reverse($this->user->id, $tx->id, 'undo');   // reversal is dated 2026-09-30 too

        expect($this->reports->totals($this->user->id, ($this->period)('2026-09-01', '2026-09-30'))['expense_minor'])->toBe(0)
            ->and($this->reports->totals($this->user->id, ($this->period)('2026-10-01', '2026-10-31'))['expense_minor'])->toBe(0);
    });

    it('never include another user\'s transactions', function () {
        seedMonth($this);
        $other = ledgerUser('919111111111');
        ledger()->post(command($other, TransactionType::Expense, '99999', ['categoryId' => category($other, 'Fuel')->id, 'occurredOn' => '2026-10-02']));

        $mine = $this->reports->totals($this->user->id, ($this->period)('2026-10-01', '2026-10-31'));
        $theirs = $this->reports->totals($other->id, ($this->period)('2026-10-01', '2026-10-31'));

        expect($mine['expense_minor'])->toBe(($this->sum)('expense', '2026-10-01', '2026-10-31'))->and($theirs['expense_minor'])->toBe(9_999_900)->and($theirs['income_minor'])->toBe(0);
    });
});

describe('breakdowns', function () {
    beforeEach(fn () => seedMonth($this));

    it('roll sub-categories up into their top-level category by default', function () {
        $rows = $this->reports->breakdown($this->user->id, ($this->period)('2026-10-01', '2026-10-31'), 'expense', 'category');

        $byLabel = collect($rows)->pluck('minor', 'label')->all();
        // Food = Vegetables 250 + Groceries 1800 + Vegetables 120; Transport = Cab 350; Shopping = 3000
        expect($byLabel)->toBe(['Shopping' => 300000, 'Food' => 217000, 'Transport' => 35000])
            ->and(array_column($rows, 'label'))->toBe(['Shopping', 'Food', 'Transport']);   // largest first
    });

    it('can show the sub-categories instead', function () {
        $rows = $this->reports->breakdown($this->user->id, ($this->period)('2026-10-01', '2026-10-31'), 'expense', 'category', null, rollup: false);

        expect(collect($rows)->pluck('minor', 'label')->all())->toEqual(['Shopping' => 300000, 'Groceries' => 180000, 'Vegetables' => 37000, 'Cab' => 35000])
            ->and(array_column($rows, 'label'))->toBe(['Shopping', 'Groceries', 'Vegetables', 'Cab']);
    });

    it('split by merchant, payment method, account, day and month, all adding up to the total', function (string $groupBy) {
        $period = ($this->period)('2026-09-01', '2026-10-31');
        $rows = $this->reports->breakdown($this->user->id, $period, 'expense', $groupBy);
        $total = ($this->sum)('expense', '2026-09-01', '2026-10-31');

        expect(array_sum(array_column($rows, 'minor')))->toBe($total);
    })->with(['category', 'merchant', 'payment_method', 'account', 'day', 'week', 'month']);

    it('label and order the dimensions sensibly', function () {
        $p = ($this->period)('2026-09-01', '2026-10-31');
        $by = fn (string $dim) => collect($this->reports->breakdown($this->user->id, $p, 'expense', $dim))->pluck('minor', 'label')->all();
        $total = ($this->sum)('expense', '2026-09-01', '2026-10-31');

        expect($by('merchant'))->toEqual(['Amazon' => 300000, 'Uber' => 35000, 'No merchant' => $total - 335000])
            ->and($by('payment_method')['Upi'])->toBe(180000)
            ->and($by('payment_method')['Credit card'])->toBe(300000)
            ->and($by('account'))->toEqual(['HDFC Credit Card' => 340000, 'Cash' => 142000, 'HDFC Bank' => 180000])   // card: 3000+400; cash: 250+350+120+700; bank: 1800
            ->and(array_column($this->reports->breakdown($this->user->id, $p, 'expense', 'month'), 'label'))->toBe(['2026-09', '2026-10'])
            ->and(array_column($this->reports->breakdown($this->user->id, $p, 'expense', 'week'), 'label')[0])->toBe('2026-09-14');   // Monday of 15 Sep
    });

    it('split income too', function () {
        $rows = $this->reports->breakdown($this->user->id, ($this->period)('2026-09-01', '2026-10-31'), 'income', 'category');

        expect(collect($rows)->pluck('minor', 'label')->all())->toBe(['Salary' => 4_500_000, 'Interest' => 100_000]);
    });

    it('drop categories that net to zero', function () {
        $tx = ($this->post)(TransactionType::Expense, '77', '2026-10-05', 'Fuel');
        ledger()->reverse($this->user->id, $tx->id, 'undo');

        $rows = $this->reports->breakdown($this->user->id, ($this->period)('2026-10-01', '2026-10-31'), 'expense', 'category');

        expect(collect($rows)->pluck('minor', 'label')['Transport'])->toBe(35000);   // Cab only; the reversed Fuel entry netted away
    });

    it('reject groupings outside the whitelist', function () {
        expect(fn () => $this->reports->breakdown($this->user->id, ($this->period)('2026-10-01', '2026-10-31'), 'expense', 'a; DROP TABLE users'))->toThrow(InvalidArgumentException::class);
    });
});

function rq(object $t, array $o): ReportQuery
{
    return new ReportQuery(...($o + ['metric' => 'total_spend', 'period' => ($t->period)('2026-09-01', '2026-10-31')]));
}

describe('filters', function () {
    beforeEach(fn () => seedMonth($this));

    it('by category include its sub-categories', function () {
        $food = category($this->user, 'Food');
        $ids = Category::where('user_id', $this->user->id)->where(fn ($q) => $q->where('id', $food->id)->orWhere('parent_id', $food->id))->pluck('id')->all();

        $r = $this->reports->totals($this->user->id, ($this->period)('2026-09-01', '2026-10-31'), rq($this, ['categoryIds' => $ids]));

        expect($r['expense_minor'])->toBe(($this->sum)('expense', '2026-09-01', '2026-10-31', fn ($t) => in_array($t['category'], $ids, true)));
    });

    it('by merchant, payment method, account and text', function () {
        $uber = Merchant::where('user_id', $this->user->id)->where('name', 'Uber')->first();
        $p = ($this->period)('2026-09-01', '2026-10-31');
        $r = fn (array $f) => $this->reports->totals($this->user->id, $p, rq($this, $f))['expense_minor'];

        expect($r(['merchantIds' => [$uber->id]]))->toBe(35000)
            ->and($r(['paymentMethod' => 'upi']))->toBe(180000)
            ->and($r(['accountIds' => [$this->card->id]]))->toBe(340000)                // the credit-card purchases
            ->and($r(['searchText' => 'office']))->toBe(35000)
            ->and($r(['searchText' => 'uber']))->toBe(35000)                             // matches the merchant name too
            ->and($r(['searchText' => 'nothing like this']))->toBe(0);
    });

    it('treat LIKE wildcards and quotes in search text literally', function () {
        $p = ($this->period)('2026-09-01', '2026-10-31');
        $r = fn (string $text) => $this->reports->totals($this->user->id, $p, rq($this, ['searchText' => $text]))['expense_minor'];

        expect($r('50%'))->toBe(12000)                 // the one description containing "50%"
            ->and($r('sabji_haul'))->toBe(12000)
            ->and($r('%'))->toBe(12000)                // a lone % matches only the literal %, not everything
            ->and($r('_'))->toBe(12000)
            ->and($r("x' OR '1'='1"))->toBe(0);
    });

    it('combine', function () {
        $p = ($this->period)('2026-09-01', '2026-10-31');

        $r = $this->reports->totals($this->user->id, $p, rq($this, ['paymentMethod' => 'credit_card', 'accountIds' => [$this->card->id]]));

        expect($r['expense_minor'])->toBe(300000);
    });
});

describe('transaction lists', function () {
    beforeEach(fn () => seedMonth($this));

    it('are newest first and exclude reversals and reversed originals', function () {
        $gone = ($this->post)(TransactionType::Expense, '77', '2026-10-05', 'Fuel');
        ledger()->reverse($this->user->id, $gone->id, 'undo');

        $list = $this->reports->transactions($this->user->id, ($this->period)('2026-10-01', '2026-10-31'), null, 50);

        expect($list->pluck('id')->contains($gone->id))->toBeFalse()->and($list->pluck('type')->map->value->contains('reversal'))->toBeFalse()
            ->and($list->pluck('occurred_on')->map->format('Y-m-d')->all())->toBe($list->pluck('occurred_on')->map->format('Y-m-d')->sortDesc()->values()->all());
    });

    it('can be ordered by amount and limited', function () {
        $top = $this->reports->transactions($this->user->id, ($this->period)('2026-10-01', '2026-10-31'), null, 2, 'amount', 'expense');

        expect($top->pluck('debit_total_minor')->all())->toBe([300000, 180000]);
    });

    it('count the matches', function () {
        expect($this->reports->countTransactions($this->user->id, ($this->period)('2026-10-01', '2026-10-31'), null))->toBe(7);   // 1 income + 5 expenses + 1 transfer
    });

    it('stay inside one user\'s data even when searching by text', function () {
        $other = ledgerUser('919111111111');
        ledger()->post(command($other, TransactionType::Expense, '5', ['categoryId' => category($other, 'Fuel')->id, 'occurredOn' => '2026-10-02', 'description' => 'office secret']));
        $q = new ReportQuery('list', ($this->period)('2026-10-01', '2026-10-31'), searchText: 'secret');

        expect($this->reports->transactions($this->user->id, $q->period, $q, 50))->toHaveCount(0)
            ->and($this->reports->transactions($other->id, $q->period, $q, 50))->toHaveCount(1);
    });
});

describe('random ledgers', function () {
    it('always agree with the oracle (including undo and correction)', function (int $seed) {
        mt_srand($seed);
        $cats = ['Vegetables', 'Groceries', 'Fuel', 'Cab', 'Shopping', 'Restaurant', 'Rent'];
        $accounts = [$this->cash, $this->bank, $this->card];
        $ids = [];
        for ($i = 0; $i < 60; $i++) {
            $date = CarbonImmutable::create(2026, 9, 1)->addDays(mt_rand(0, 59))->format('Y-m-d');
            $roll = mt_rand(1, 100);
            if ($roll <= 70) {
                $tx = ($this->post)(TransactionType::Expense, (string) mt_rand(1, 9999), $date, $cats[array_rand($cats)], ['account' => $accounts[array_rand($accounts)]]);
                $ids[] = $tx->id;
            } elseif ($roll <= 85) {
                ($this->post)(TransactionType::Income, (string) mt_rand(1, 99999), $date, 'Salary', ['account' => $this->bank]);
            } elseif ($roll <= 93 && $ids) {
                $victim = $ids[array_rand($ids)];
                if ($this->oracle[$victim]['active']) {
                    ledger()->reverse($this->user->id, $victim, 'undo');
                    $this->oracle[$victim]['active'] = false;
                }
            } else {
                ($this->post)(TransactionType::Transfer, (string) mt_rand(1, 999), $date, null, ['account' => $this->bank, 'to' => $this->cash->id]);
            }
        }

        foreach ([['2026-09-01', '2026-09-30'], ['2026-10-01', '2026-10-31'], ['2026-09-10', '2026-10-20']] as [$from, $to]) {
            $p = ($this->period)($from, $to);
            $totals = $this->reports->totals($this->user->id, $p);
            $rows = $this->reports->breakdown($this->user->id, $p, 'expense', 'category');

            expect($totals['expense_minor'])->toBe(($this->sum)('expense', $from, $to))
                ->and($totals['income_minor'])->toBe(($this->sum)('income', $from, $to))
                ->and(array_sum(array_column($rows, 'minor')))->toBe($totals['expense_minor']);
        }
    })->with([1, 2, 3, 11, 42, 99]);
});
