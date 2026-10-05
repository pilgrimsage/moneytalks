<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Loan extends Model
{
    use BelongsToUser, HasUlids;

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = ['rate_bp' => 'integer', 'emi_minor' => 'integer', 'opening_minor' => 'integer', 'started_on' => 'immutable_date'];
}
