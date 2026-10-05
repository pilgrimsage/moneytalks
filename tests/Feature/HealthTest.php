<?php

use Illuminate\Support\Facades\DB;

it('answers the liveness probe', function () {
    $this->get('/up')->assertOk();
});

it('runs against MySQL/MariaDB with utf8mb4 (needed for Devanagari text)', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql');

    $charset = DB::selectOne('SELECT @@character_set_database AS c')->c;
    expect($charset)->toBe('utf8mb4');
});

it('stores and returns Devanagari text intact', function () {
    DB::table('cache')->insert(['key' => 'k', 'value' => 'सब्जी', 'expiration' => 9999999999]);

    expect(DB::table('cache')->where('key', 'k')->value('value'))->toBe('सब्जी');
});
