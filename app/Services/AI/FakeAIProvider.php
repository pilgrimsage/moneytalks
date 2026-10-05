<?php

namespace App\Services\AI;

use App\Services\AI\DTO\StructuredRequest;
use App\Services\AI\DTO\StructuredResponse;
use Closure;
use Throwable;

/**
 * No-network provider for local development, tests and fixture-replay evals. Queue scripted
 * results with respond()/fail(), or install a responder closure. Refused in production.
 */
class FakeAIProvider implements AIProvider
{
    /** @var list<StructuredRequest> */
    public static array $requests = [];

    /** @var list<array|Throwable|StructuredResponse> */
    private static array $queue = [];

    private static ?Closure $responder = null;

    public static function reset(): void
    {
        self::$requests = [];
        self::$queue = [];
        self::$responder = null;
    }

    /** Queue a model answer (array = JSON the model "returned"), a StructuredResponse, or an exception to throw. */
    public static function respond(array|Throwable|StructuredResponse $next): void
    {
        self::$queue[] = $next;
    }

    /** @param Closure(StructuredRequest): (array|Throwable|StructuredResponse) $fn */
    public static function responder(Closure $fn): void
    {
        self::$responder = $fn;
    }

    public function name(): string
    {
        return 'fake';
    }

    /** Fake answers mimic Claude models, so they are priced from the Anthropic list (useful for eval cost estimates). */
    public function pricingProvider(): string
    {
        return 'anthropic';
    }

    public function structured(StructuredRequest $request): StructuredResponse
    {
        self::$requests[] = $request;

        $next = self::$queue !== [] ? array_shift(self::$queue) : (self::$responder ? (self::$responder)($request) : null);
        if ($next === null) {
            throw new \LogicException('FakeAIProvider has nothing scripted for this request.');
        }
        if ($next instanceof Throwable) {
            throw $next;
        }
        if ($next instanceof StructuredResponse) {
            return $next;
        }

        return new StructuredResponse(
            data: $next, rawText: json_encode($next, JSON_UNESCAPED_UNICODE), model: $request->model, status: 'ok',
            inputTokens: 900 + intdiv(strlen($request->user), 4), outputTokens: 120, requestId: 'fake_'.bin2hex(random_bytes(4)), latencyMs: 5,
        );
    }
}
