<?php

namespace App\Models;

use App\Enums\Direction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** One debit or credit line. Strictly immutable. */
class LedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = ['direction' => Direction::class, 'amount_minor' => 'integer'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Ledger entries are immutable.'));
        static::deleting(fn () => throw new LogicException('Ledger entries cannot be deleted.'));
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class, 'transaction_id');
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'account_id');
    }
}
