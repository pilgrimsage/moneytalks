<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiModelPrice extends Model
{
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = ['effective_from' => 'date'];
}
