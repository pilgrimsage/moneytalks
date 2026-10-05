<?php

namespace App\Domain\Ledger;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Context;

class AuditLogger
{
    public function record(
        string $action,
        ?string $userId,
        string $subjectType,
        string $subjectId,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        string $actorType = 'system',
        ?string $actorId = null,
    ): void {
        AuditLog::create([
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'user_id' => $userId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'correlation_id' => Context::get('correlation_id'),
        ]);
    }
}
