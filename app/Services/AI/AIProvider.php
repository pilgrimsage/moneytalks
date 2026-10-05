<?php

namespace App\Services\AI;

use App\Services\AI\DTO\StructuredRequest;
use App\Services\AI\DTO\StructuredResponse;
use App\Services\AI\Exceptions\AIPermanentException;
use App\Services\AI\Exceptions\AITransientException;

/**
 * The only seam between the application and an LLM vendor. Returns schema-shaped JSON; the
 * application re-validates it (the schema is a guardrail, not a trust boundary).
 */
interface AIProvider
{
    public function name(): string;

    /** Which price list (ai_model_prices.provider) applies to this provider's models. */
    public function pricingProvider(): string;

    /**
     * @throws AITransientException
     * @throws AIPermanentException
     */
    public function structured(StructuredRequest $request): StructuredResponse;
}
