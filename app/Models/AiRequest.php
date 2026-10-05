<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiRequest extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $hidden = ['input', 'output'];

    /** @var array<string, string> */
    protected $casts = ['input' => 'encrypted', 'output' => 'encrypted'];
}
