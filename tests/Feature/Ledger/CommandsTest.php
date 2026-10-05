<?php

use App\Enums\EntityType;
use App\Models\LedgerTransaction;
use App\Services\Interpretation\EntityResolver;
use Illuminate\Console\Scheduling\Schedule;

beforeEach(function () {
    $this->user = ledgerUser();
});

it('creates an account with an opening balance, aliases and default', function () {
    $this->artisan('moneytalks:account:create', [
        'wa_id' => '919876543210', 'name' => 'HDFC Bank', 'subtype' => 'bank',
        '--opening' => '52340.50', '--alias' => ['hdfc'], '--default' => true,
    ])->expectsOutputToContain('₹52,340.50')->assertSuccessful();

    $bank = account($this->user, 'HDFC Bank');
    expect(balanceOf($bank))->toBe(5234050)
        ->and($this->user->settings->fresh()->default_account_id)->toBe($bank->id)
        ->and(app(EntityResolver::class)->resolve($this->user->id, EntityType::Account, 'HDFC')->entityId)->toBe($bank->id)
        ->and(LedgerTransaction::where('type', 'opening_balance')->count())->toBe(1);
});

it('shows a credit card opening balance as money owed, and net worth accordingly', function () {
    $this->artisan('moneytalks:account:create', ['wa_id' => '919876543210', 'name' => 'Bank', 'subtype' => 'bank', '--opening' => '10000'])->assertSuccessful();
    $this->artisan('moneytalks:account:create', ['wa_id' => '919876543210', 'name' => 'HDFC CC', 'subtype' => 'credit_card', '--opening' => '2500'])->assertSuccessful();

    $this->artisan('moneytalks:balances', ['wa_id' => '919876543210'])
        ->expectsOutputToContain('Net worth (assets - liabilities): ₹7,500.00')
        ->assertSuccessful();
});

it('rejects bad input with a message, never a stack trace', function (array $args, string $message) {
    $this->artisan('moneytalks:account:create', ['wa_id' => '919876543210'] + $args)
        ->expectsOutputToContain($message)->assertFailed();
})->with([
    'unknown subtype' => [['name' => 'X', 'subtype' => 'bitcoin'], 'Unknown subtype'],
    'duplicate name' => [['name' => 'Cash', 'subtype' => 'cash'], 'already exists'],
    'bad opening amount' => [['name' => 'Y', 'subtype' => 'bank', '--opening' => '12.345'], 'Too many decimal places'],
]);

it('fails for unknown users', function () {
    $this->artisan('moneytalks:account:create', ['wa_id' => '910000000000', 'name' => 'A', 'subtype' => 'bank'])->assertFailed();
    $this->artisan('moneytalks:balances', ['wa_id' => '910000000000'])->assertFailed();
});

it('schedules the nightly ledger verification', function () {
    $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command);

    expect($events->implode(' '))->toContain('moneytalks:ledger:verify');
});
