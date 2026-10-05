<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DebtRecord extends Model
{
    use BelongsToUser, HasUlids;

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = ['original_minor' => 'integer', 'due_on' => 'immutable_date'];

    public function settlements(): HasMany
    {
        return $this->hasMany(DebtSettlement::class);
    }
}
