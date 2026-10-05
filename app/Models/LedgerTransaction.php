<?php

namespace App\Models;

use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Header of a double-entry transaction. Append-only: once posted, only `status` and
 * `reversed_by_id` may change (when a reversal is posted). Corrections create new rows.
 */
class LedgerTransaction extends Model
{
    use BelongsToUser, HasUlids;

    /** Columns that may be updated after posting. */
    private const MUTABLE = ['status', 'reversed_by_id', 'updated_at'];

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'type' => TransactionType::class,
        'status' => TransactionStatus::class,
        'source' => TransactionSource::class,
        'occurred_on' => 'immutable_date',
        'occurred_at' => 'immutable_datetime',
        'confidence' => 'float',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $tx) {
            $illegal = array_diff(array_keys($tx->getDirty()), self::MUTABLE);
            if ($illegal !== []) {
                throw new LogicException('Ledger transactions are immutable; illegal change to: '.implode(', ', $illegal));
            }
        });

        static::deleting(fn () => throw new LogicException('Ledger transactions cannot be deleted; reverse them instead.'));
    }

    /** @return HasMany<LedgerEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'transaction_id')->orderBy('position');
    }

    public function isReversible(): bool
    {
        return $this->status === TransactionStatus::Posted && $this->type !== TransactionType::Reversal;
    }
}
