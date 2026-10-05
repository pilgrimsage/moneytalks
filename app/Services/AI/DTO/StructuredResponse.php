<?php

namespace App\Services\AI\DTO;

final class StructuredResponse
{
    public function __construct(
        /** Decoded JSON, or null when the model produced none (refusal, truncation, invalid JSON). */
        public readonly ?array $data,
        public readonly string $rawText,
        public readonly string $model,
        public readonly string $status,        // ok | refusal | truncated | invalid_json
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $cacheReadTokens = 0,
        public readonly int $cacheWriteTokens = 0,
        public readonly ?string $requestId = null,
        public readonly int $latencyMs = 0,
    ) {}

    public function isOk(): bool
    {
        return $this->status === 'ok' && $this->data !== null;
    }
}
