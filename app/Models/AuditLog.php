<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Append-only record of who changed what. */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = ['before' => 'array', 'after' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit logs are append-only.'));
        static::deleting(fn () => throw new LogicException('Audit logs are append-only.'));
    }
}
