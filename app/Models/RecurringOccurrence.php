<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class RecurringOccurrence extends Model
{
    use BelongsToUser, HasUlids;

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = ['due_on' => 'immutable_date', 'last_reminded_at' => 'immutable_datetime'];
}
