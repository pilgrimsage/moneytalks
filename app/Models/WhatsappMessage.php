<?php

namespace App\Models;

use App\Enums\MessageStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** An inbound or outbound WhatsApp message. Body and raw payload are encrypted at rest. */
class WhatsappMessage extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $hidden = ['text', 'payload', 'peer_bidx'];

    /** @var array<string, string> */
    protected $casts = [
        'status' => MessageStatus::class,
        'text' => 'encrypted',
        'payload' => 'encrypted:array',
        'billable' => 'boolean',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
        'sent_at' => 'datetime',
    ];
}
