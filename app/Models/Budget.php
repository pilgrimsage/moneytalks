<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Budget extends Model
{
    use BelongsToUser, HasUlids;

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = ['amount_minor' => 'integer'];
}
