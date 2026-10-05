<?php

namespace App\Services\AI;

/**
 * Chooses which model handles a request (docs/ai.md section 5). Haiku-first; the stronger model is
 * only ever used as an escalation when explicitly enabled and configured. Model ids come from config.
 */
class ModelRouter
{
    public function primary(string $requestType): string
    {
        return (string) config('ai.models.fast');
    }

    /** The stronger model for escalation, or null when escalation is off or not configured. */
    public function escalation(string $requestType): ?string
    {
        $strong = config('ai.models.strong');

        return config('ai.escalation_enabled') && $strong && $strong !== $this->primary($requestType) ? (string) $strong : null;
    }
}
