<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Counterparty extends Model
{
    use BelongsToUser, HasUlids;

    protected $guarded = [];

    protected $hidden = ['phone_enc'];
}
