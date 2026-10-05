<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class ConversationState extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $hidden = ['payload'];

    /** @var array<string, string> */
    protected $casts = ['payload' => 'encrypted:array', 'expires_at' => 'datetime'];

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
