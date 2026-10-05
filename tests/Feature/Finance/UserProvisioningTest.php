<?php

use App\Domain\Finance\DefaultCatalog;
use App\Domain\Finance\UserProvisioner;
use App\Models\Category;
use App\Models\LedgerAccount;
use App\Models\Merchant;
use App\Models\User;
use App\Models\UserAlias;

it('creates a user with defaults, settings and the seeded catalog', function () {
    $user = app(UserProvisioner::class)->provision('+91 98765 43210', 'Asha');

    expect($user->name)->toBe('Asha')
        ->and($user->timezone)->toBe('Asia/Kolkata')
        ->and($user->base_currency)->toBe('INR')
        ->and($user->settings)->not->toBeNull()
        ->and(Category::where('user_id', $user->id)->where('path', 'Food > Vegetables')->exists())->toBeTrue()
        ->and(Merchant::where('user_id', $user->id)->where('name', 'Netflix')->exists())->toBeTrue();
});

it('is idempotent: provisioning twice creates nothing new', function () {
    $p = app(UserProvisioner::class);
    $user = $p->provision('919876543210');
    $counts = [Category::count(), UserAlias::count(), Merchant::count(), User::count()];

    $again = $p->provision('+91-98765-43210');

    expect($again->id)->toBe($user->id)
        ->and([Category::count(), UserAlias::count(), Merchant::count(), User::count()])->toBe($counts);
});

it('seeds every catalog alias', function () {
    $user = app(UserProvisioner::class)->provision('919876543210');
    $veg = Category::where('user_id', $user->id)->where('name', 'Vegetables')->first();

    foreach (['sabji', 'sabzi', 'सब्जी', 'veg'] as $alias) {
        expect(UserAlias::where('user_id', $user->id)->where('alias', $alias)->value('entity_id'))->toBe($veg->id);
    }
});

it('builds the category tree with parents', function () {
    $user = app(UserProvisioner::class)->provision('919876543210');
    $veg = Category::where('user_id', $user->id)->where('name', 'Vegetables')->first();

    expect($veg->parent->name)->toBe('Food')
        ->and($veg->kind->value)->toBe('expense')
        ->and(Category::where('user_id', $user->id)->where('kind', 'income')->where('name', 'Salary')->exists())->toBeTrue();
});

it('stores the WhatsApp number encrypted and finds users through the blind index', function () {
    $user = app(UserProvisioner::class)->provision('+91 98765 43210');
    $raw = DB::table('users')->where('id', $user->id)->first();

    expect($raw->wa_id_enc)->not->toContain('9876543210')
        ->and($raw->wa_id_bidx)->toHaveLength(64)
        ->and($user->fresh()->waId())->toBe('919876543210')
        ->and(User::findByWaId('91 9876543210')?->id)->toBe($user->id)
        ->and(User::findByWaId('919999999999'))->toBeNull();
});

it('hides the encrypted number when serialised', function () {
    $user = app(UserProvisioner::class)->provision('919876543210');

    expect($user->toArray())->not->toHaveKeys(['wa_id_enc', 'wa_id_bidx']);
});

it('counts exactly the catalog it was given', function () {
    $user = app(UserProvisioner::class)->provision('919876543210');
    $expected = 0;
    $walk = function (array $nodes) use (&$walk, &$expected) {
        foreach ($nodes as $node) {
            $expected++;
            $walk($node['children'] ?? []);
        }
    };
    $walk(DefaultCatalog::categories()['expense']);
    $walk(DefaultCatalog::categories()['income']);

    expect(Category::where('user_id', $user->id)->count())->toBe($expected);
});

it('creates the system ledger accounts and a default Cash account', function () {
    $user = app(UserProvisioner::class)->provision('919876543210');
    $names = LedgerAccount::where('user_id', $user->id)->pluck('name')->all();

    expect($names)->toContain('Expenses', 'Income', 'Opening Balances', 'Reconciliation Adjustments', 'Cash')
        ->and($user->settings->fresh()->default_account_id)->toBe(account($user, 'Cash')->id);

    app(UserProvisioner::class)->provision('919876543210');
    expect(LedgerAccount::where('user_id', $user->id)->count())->toBe(5);
});
