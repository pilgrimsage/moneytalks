<?php

use App\Domain\Ledger\AccountService;
use App\Domain\Ledger\LedgerVerifier;
use App\Enums\AccountSubtype;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Category;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Property-style tests: drive the ledger with seeded random operations and assert the
 * invariants that must hold no matter what sequence of events a user produces.
 */
beforeEach(function () {
    $this->user = ledgerUser();
    $svc = app(AccountService::class);
    $this->owned = [
        account($this->user, 'Cash'),
        $svc->create($this->user, 'HDFC Bank', AccountSubtype::Bank),
        $svc->create($this->user, 'GPay Wallet', AccountSubtype::Wallet),
    ];
    $this->spendable = [...$this->owned, $svc->create($this->user, 'HDFC Credit Card', AccountSubtype::CreditCard)];
    $this->expenseCats = Category::where('user_id', $this->user->id)->where('kind', 'expense')->pluck('id')->all();
    $this->incomeCats = Category::where('user_id', $this->user->id)->where('kind', 'income')->pluck('id')->all();
});

function pick(array $a)
{
    return $a[mt_rand(0, count($a) - 1)];
}

/** Apply one random valid operation; returns a label for debugging. */
function randomOperation(object $t): string
{
    $minor = mt_rand(1, 5_000_000);
    $money = Money::ofMinor($minor, 'INR');
    $roll = mt_rand(1, 100);

    $posted = LedgerTransaction::where('user_id', $t->user->id)->where('status', 'posted')->where('type', '!=', 'reversal')->pluck('id')->all();

    if ($roll <= 40) {
        ledger()->post(command($t->user, TransactionType::Expense, '1', [
            'money' => $money, 'accountId' => pick($t->spendable)->id, 'categoryId' => pick($t->expenseCats),
        ]));

        return 'expense';
    }
    if ($roll <= 60) {
        ledger()->post(command($t->user, TransactionType::Income, '1', [
            'money' => $money, 'accountId' => pick($t->owned)->id, 'categoryId' => pick($t->incomeCats),
        ]));

        return 'income';
    }
    if ($roll <= 75) {
        $from = pick($t->owned);
        $to = pick(array_values(array_filter($t->owned, fn ($a) => $a->id !== $from->id)));
        ledger()->post(command($t->user, TransactionType::Transfer, '1', ['money' => $money, 'accountId' => $from->id, 'toAccountId' => $to->id]));

        return 'transfer';
    }
    if ($roll <= 85) {
        ledger()->post(command($t->user, TransactionType::OpeningBalance, '1', ['money' => $money, 'accountId' => pick($t->spendable)->id]));

        return 'opening';
    }
    if ($posted === []) {
        return 'noop';
    }
    if ($roll <= 93) {
        ledger()->reverse($t->user->id, pick($posted), 'random undo');

        return 'reverse';
    }
    ledger()->correct(pick($posted), command($t->user, TransactionType::Expense, '1', [
        'money' => $money, 'accountId' => pick($t->spendable)->id, 'categoryId' => pick($t->expenseCats),
    ]), 'random correction');

    return 'correct';
}

it('keeps every invariant across random event sequences', function (int $seed) {
    mt_srand($seed);
    $ops = [];
    for ($i = 0; $i < 120; $i++) {
        $ops[] = randomOperation($this);
    }

    // 1. The verifier (header CHECKs, entry sums, hashes, reversal links) finds nothing.
    expect(app(LedgerVerifier::class)->verify())->toBe([]);

    // 2. Total debits equal total credits, overall.
    $d = (int) LedgerEntry::where('direction', 'D')->sum('amount_minor');
    $c = (int) LedgerEntry::where('direction', 'C')->sum('amount_minor');
    expect($d)->toBe($c)->and($d)->toBeGreaterThan(0);

    // 3. Every transaction balances on its own.
    $unbalanced = DB::table('ledger_transactions')->whereColumn('debit_total_minor', '!=', 'credit_total_minor')->count();
    expect($unbalanced)->toBe(0);

    // 4. SQL balances equal a from-scratch replay of all entries in PHP.
    $replay = [];
    foreach (LedgerEntry::orderBy('id')->get() as $e) {
        $replay[$e->account_id] = ($replay[$e->account_id] ?? 0) + ($e->direction->value === 'D' ? $e->amount_minor : -$e->amount_minor);
    }
    $sql = app(AccountService::class)->balances($this->user->id);
    $sumNatural = ['asset' => 0, 'liability' => 0, 'equity' => 0, 'income' => 0, 'expense' => 0];
    foreach (LedgerAccount::where('user_id', $this->user->id)->get() as $a) {
        $net = $replay[$a->id] ?? 0;
        $natural = $a->kind->isDebitNormal() ? $net : -$net;
        expect($sql[$a->id]->minor)->toBe($natural);
        $sumNatural[$a->kind->value] += $natural;
    }

    // 5. The accounting equation: Assets = Liabilities + Equity + Income - Expenses.
    expect($sumNatural['asset'])->toBe($sumNatural['liability'] + $sumNatural['equity'] + $sumNatural['income'] - $sumNatural['expense']);

    // 6. A reversed transaction and its reversal cancel exactly.
    foreach (LedgerTransaction::where('status', 'reversed')->get() as $orig) {
        $rev = LedgerTransaction::find($orig->reversed_by_id);
        expect($rev->reversal_of_id)->toBe($orig->id)
            ->and($rev->debit_total_minor)->toBe($orig->debit_total_minor);
    }

    // The run actually exercised a mix of operations.
    expect(array_unique($ops))->toContain('expense', 'income', 'transfer');
})->with([1, 2, 3, 7, 42, 99, 2026, 31337]);

it('restores every balance exactly when a posting is reversed', function (int $seed) {
    mt_srand($seed);
    for ($i = 0; $i < 25; $i++) {
        randomOperation($this);
    }

    $before = array_map(fn (Money $m) => $m->minor, app(AccountService::class)->balances($this->user->id));

    $tx = ledger()->post(command($this->user, TransactionType::Expense, '1', [
        'money' => Money::ofMinor(mt_rand(1, 9_999_999), 'INR'),
        'accountId' => pick($this->spendable)->id, 'categoryId' => pick($this->expenseCats),
    ]))->transaction;

    expect(array_map(fn (Money $m) => $m->minor, app(AccountService::class)->balances($this->user->id)))->not->toBe($before);

    ledger()->reverse($this->user->id, $tx->id, 'undo');

    expect(array_map(fn (Money $m) => $m->minor, app(AccountService::class)->balances($this->user->id)))->toBe($before)
        ->and($tx->fresh()->status)->toBe(TransactionStatus::Reversed);
})->with([5, 11, 123, 4242]);

it('never lets a replayed batch of idempotency keys change any balance', function () {
    mt_srand(77);
    $cmds = [];
    for ($i = 0; $i < 30; $i++) {
        $cmds[] = command($this->user, TransactionType::Expense, '1', [
            'money' => Money::ofMinor(mt_rand(1, 100000), 'INR'), 'categoryId' => pick($this->expenseCats),
        ]);
    }
    foreach ($cmds as $cmd) {
        ledger()->post($cmd);
    }
    $snapshot = array_map(fn (Money $m) => $m->minor, app(AccountService::class)->balances($this->user->id));
    $count = LedgerTransaction::count();

    // Webhook redelivery: every message arrives again, several times, in a different order.
    foreach ([...array_reverse($cmds), ...$cmds, ...array_reverse($cmds)] as $cmd) {
        expect(ledger()->post($cmd)->replayed)->toBeTrue();
    }

    expect(LedgerTransaction::count())->toBe($count)
        ->and(array_map(fn (Money $m) => $m->minor, app(AccountService::class)->balances($this->user->id)))->toBe($snapshot);
});

it('keeps two users completely independent', function () {
    $other = ledgerUser('919111111111');
    mt_srand(9);
    for ($i = 0; $i < 40; $i++) {
        randomOperation($this);
    }
    $mine = array_map(fn (Money $m) => $m->minor, app(AccountService::class)->balances($this->user->id));

    ledger()->post(command($other, TransactionType::Expense, '123', ['categoryId' => category($other, 'Fuel')->id]));

    expect(array_map(fn (Money $m) => $m->minor, app(AccountService::class)->balances($this->user->id)))->toBe($mine)
        ->and(balanceOf(account($other, 'Cash')))->toBe(-12300)
        ->and(app(LedgerVerifier::class)->verify())->toBe([]);
});
