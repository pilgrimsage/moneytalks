<?php

namespace App\Models;

use App\Enums\EntityType;
use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Model;

class UserAlias extends Model
{
    use BelongsToUser;

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = ['entity_type' => EntityType::class];
}
