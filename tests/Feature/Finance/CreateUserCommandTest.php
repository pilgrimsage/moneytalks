<?php

use App\Models\User;

it('creates a user from an allowed number', function () {
    config(['moneytalks.allowed_wa_ids' => ['919876543210']]);

    $this->artisan('moneytalks:user:create', ['wa_id' => '+91 98765 43210', '--name' => 'Asha'])
        ->expectsOutputToContain('ready')
        ->assertSuccessful();

    expect(User::findByWaId('919876543210')?->name)->toBe('Asha');
});

it('refuses numbers outside the allow-list', function () {
    config(['moneytalks.allowed_wa_ids' => ['919876543210']]);

    $this->artisan('moneytalks:user:create', ['wa_id' => '919000000000'])->assertFailed();

    expect(User::count())->toBe(0);
});

it('rejects input without digits', function () {
    $this->artisan('moneytalks:user:create', ['wa_id' => 'abc'])->assertFailed();
});
