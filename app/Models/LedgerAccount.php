<?php

namespace App\Models;

use App\Enums\AccountKind;
use App\Enums\AccountSubtype;
use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LedgerAccount extends Model
{
    use BelongsToUser, HasUlids;

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'kind' => AccountKind::class,
        'subtype' => AccountSubtype::class,
        'is_system' => 'boolean',
        'meta' => 'array',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'account_id');
    }
}
