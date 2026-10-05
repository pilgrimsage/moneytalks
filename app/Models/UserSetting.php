<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserSetting extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'notification_prefs' => 'array',
        'feature_overrides' => 'array',
    ];
}
