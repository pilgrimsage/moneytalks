<?php

namespace App\Services\Interpretation;

final class InterpretationResult
{
    public function __construct(
        /** @var list<Decision> */
        public readonly array $decisions,
        /** ok | invalid_output | daily_limit */
        public readonly string $status,
        public readonly ?int $aiRequestId = null,
        public readonly ?string $model = null,
        public readonly ?string $detail = null,
    ) {}
}
