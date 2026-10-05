<?php

namespace App\Domain\Ledger;

use App\Domain\Ledger\Exceptions\LedgerException;
use App\Models\Counterparty;
use App\Models\User;

/** The people the user lends to, borrows from or splits with. One record per name per user. */
class CounterpartyService
{
    public function findOrCreate(User $user, string $name): Counterparty
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '' || mb_strlen($name, 'UTF-8') > 60) {
            throw new LedgerException('A person needs a name of up to 60 characters.');
        }

        // Names are unique per user (case-insensitive collation), so a repeat finds the existing person.
        return Counterparty::firstOrCreate(['user_id' => $user->id, 'name' => $name]);
    }
}
