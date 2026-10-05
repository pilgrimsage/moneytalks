<?php

namespace App\Services\AI\DTO;

final class StructuredRequest
{
    public function __construct(
        public readonly string $model,
        public readonly string $system,
        public readonly string $user,
        /** JSON Schema (structured outputs: objects need additionalProperties=false, no min/max constraints). */
        public readonly array $schema,
        public readonly int $maxTokens = 1024,
        /** @var list<array{mime: string, data: string}> images as base64 (receipt photos); never persisted */
        public readonly array $images = [],
    ) {}
}
