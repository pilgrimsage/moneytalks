<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        // The raw delivery contains message bodies and numbers (strangers' too): encrypted at rest, cleared after 14 days.
        'payload' => 'encrypted',
        'received_at' => 'datetime',
        'processing_started_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
