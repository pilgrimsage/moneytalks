<?php

use App\Domain\Finance\UserProvisioner;
use App\Domain\Ledger\AccountService;
use App\Enums\AccountSubtype;
use App\Enums\CategoryKind;
use App\Enums\EntityType;
use App\Models\Category;
use App\Models\Counterparty;
use App\Models\UserAlias;
use App\Services\Interpretation\EntityResolver;

beforeEach(function () {
    $this->user = app(UserProvisioner::class)->provision('919876543210');
    $this->resolver = app(EntityResolver::class);
});

function resolveCategory(string $text, ?CategoryKind $kind = null)
{
    return test()->resolver->resolve(test()->user->id, EntityType::Category, $text, $kind);
}

it('resolves exact aliases, including Hinglish and Devanagari', function (string $input, string $expected) {
    $r = resolveCategory($input);
    expect($r->isResolved())->toBeTrue()
        ->and($r->name)->toBe($expected)
        ->and($r->matchType)->toBe('alias');
})->with([
    ['sabji', 'Vegetables'],
    ['SABJI', 'Vegetables'],
    ['  Sabzi! ', 'Vegetables'],
    ['सब्जी', 'Vegetables'],
    ['petrol', 'Fuel'],
    ['chai', 'Tea/Coffee'],
    ['bijli', 'Electricity'],
    ['recharge', 'Mobile'],
    ['kiraya', 'Rent'],
    ['ghar ka kharcha', 'Household'],
]);

it('resolves exact category names', function () {
    $r = resolveCategory('Groceries');
    expect($r->isResolved())->toBeTrue()->and($r->name)->toBe('Groceries');
});

it('fuzzy-matches typos', function (string $input, string $expected) {
    $r = resolveCategory($input);
    expect($r->isResolved())->toBeTrue()
        ->and($r->matchType)->toBe('fuzzy')
        ->and($r->name)->toBe($expected);
})->with([
    ['vegtables', 'Vegetables'],
    ['grocries', 'Groceries'],
    ['electricty', 'Electricity'],
]);

it('does not guess for unknown or hallucinated names', function (string $input) {
    expect(resolveCategory($input)->isUnresolved())->toBeTrue();
})->with(['spaceship fuel', 'xyzzy', '', '   ', '!!!']);

it('does not fuzzy-match very short strings', function () {
    expect(resolveCategory('vgt')->isUnresolved())->toBeTrue();
});

it('filters by category kind', function () {
    expect(resolveCategory('salary', CategoryKind::Income)->isResolved())->toBeTrue()
        ->and(resolveCategory('salary', CategoryKind::Expense)->isUnresolved())->toBeTrue();
});

it('reports ambiguity when several records share a name', function () {
    // "Other" would be ambiguous if two leaves had it; build one explicitly.
    $parent = Category::where('user_id', $this->user->id)->where('name', 'Shopping')->first();
    Category::create(['user_id' => $this->user->id, 'kind' => 'expense', 'name' => 'Fuel', 'path' => 'Shopping > Fuel', 'parent_id' => $parent->id]);
    UserAlias::where('user_id', $this->user->id)->where('alias', 'fuel')->delete();

    $r = resolveCategory('fuel');
    expect($r->isAmbiguous())->toBeTrue()->and($r->candidates)->toHaveCount(2);
});

it('resolves merchants, with aliases', function () {
    $r = $this->resolver->resolve($this->user->id, EntityType::Merchant, 'amzn');
    expect($r->isResolved())->toBeTrue()->and($r->name)->toBe('Amazon');
});

it('resolves counterparties by name and fuzzy name', function () {
    Counterparty::create(['user_id' => $this->user->id, 'name' => 'Rahul']);
    Counterparty::create(['user_id' => $this->user->id, 'name' => 'Amit']);

    expect($this->resolver->resolve($this->user->id, EntityType::Counterparty, 'rahul')->name)->toBe('Rahul')
        ->and($this->resolver->resolve($this->user->id, EntityType::Counterparty, 'Suresh')->isUnresolved())->toBeTrue();
});

it('never auto-resolves a person from a fuzzy match; it asks instead', function () {
    Counterparty::create(['user_id' => $this->user->id, 'name' => 'Rahul']);

    $r = $this->resolver->resolve($this->user->id, EntityType::Counterparty, 'rahull');
    expect($r->isAmbiguous())->toBeTrue()
        ->and($r->candidates[0]['name'])->toBe('Rahul');
});

it('flags near-ties between different people as ambiguous', function () {
    Counterparty::create(['user_id' => $this->user->id, 'name' => 'Rahul']);
    Counterparty::create(['user_id' => $this->user->id, 'name' => 'Rahil']);

    $r = $this->resolver->resolve($this->user->id, EntityType::Counterparty, 'Rahim');
    expect($r->isAmbiguous())->toBeTrue();
});

it('never resolves across users (tenant isolation)', function () {
    $other = app(UserProvisioner::class)->provision('919111111111');
    Counterparty::create(['user_id' => $other->id, 'name' => 'Priya']);
    UserAlias::create(['user_id' => $other->id, 'entity_type' => 'category', 'entity_id' => 'x', 'alias' => 'secretword']);

    expect($this->resolver->resolve($this->user->id, EntityType::Counterparty, 'Priya')->isUnresolved())->toBeTrue()
        ->and(resolveCategory('secretword')->isUnresolved())->toBeTrue();
});

it('ignores aliases that point at deleted entities', function () {
    UserAlias::create(['user_id' => $this->user->id, 'entity_type' => 'category', 'entity_id' => '01JGONE000000000000000000', 'alias' => 'ghostcat']);

    expect(resolveCategory('ghostcat')->isUnresolved())->toBeTrue();
});

it('resolves the user\'s own accounts by name and alias, but not system or per-person accounts', function () {
    $svc = app(AccountService::class);
    $bank = $svc->create($this->user, 'HDFC Bank', AccountSubtype::Bank);
    UserAlias::create(['user_id' => $this->user->id, 'entity_type' => 'account', 'entity_id' => $bank->id, 'alias' => 'hdfc']);

    $resolve = fn (string $text) => $this->resolver->resolve($this->user->id, EntityType::Account, $text);

    expect($resolve('hdfc')->name)->toBe('HDFC Bank')
        ->and($resolve('HDFC Bank')->name)->toBe('HDFC Bank')
        ->and($resolve('cash')->name)->toBe('Cash')
        ->and($resolve('नकद')->name)->toBe('Cash')
        ->and($resolve('expenses')->isUnresolved())->toBeTrue()
        ->and($resolve('opening balances')->isUnresolved())->toBeTrue();
});
