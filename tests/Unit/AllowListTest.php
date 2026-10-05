<?php

use App\Support\AllowList;

it('keeps only digits and drops empties', function () {
    expect(AllowList::parse('+91 98765-43210, 12345 ,,abc'))->toBe(['919876543210', '12345'])
        ->and(AllowList::parse(null))->toBe([])
        ->and(AllowList::parse(''))->toBe([]);
});

it('uses the list of the active channel, so switching channels is one setting', function () {
    expect(AllowList::forProvider('telegram', '424242424', '919876543210'))->toBe(['424242424'])
        ->and(AllowList::forProvider('meta', '424242424', '919876543210'))->toBe(['919876543210'])
        ->and(AllowList::forProvider('fake', '424242424', '919876543210'))->toBe(['919876543210']);
});

it('lets Telegram fall back to the single shared list, and never borrows the other way', function () {
    expect(AllowList::forProvider('telegram', '', '424242424'))->toBe(['424242424'])
        ->and(AllowList::forProvider('telegram', null, '424242424'))->toBe(['424242424'])
        ->and(AllowList::forProvider('meta', '424242424', ''))->toBe([]); // empty = everyone ignored (fail closed)
});
