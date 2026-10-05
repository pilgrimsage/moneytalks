<?php

namespace App\Services\AI;

use App\Models\AiRequest;
use App\Models\PromptTemplate;
use App\Models\User;
use App\Services\AI\DTO\StructuredRequest;
use App\Services\AI\DTO\StructuredResponse;
use App\Services\AI\Exceptions\AIPermanentException;
use App\Services\AI\Exceptions\AITransientException;
use App\Services\AI\Exceptions\AIUnavailableException;
use App\Services\AI\Prompts\ReceiptParser;
use App\Services\AI\Prompts\TransactionParser;
use Illuminate\Support\Facades\Log;

/**
 * Every LLM call in the app goes through here: routing, one retry on transient errors, and a
 * recorded ai_requests row (tokens, cost, latency, status) for every attempt, success or failure.
 * A failed call never loses the user's message: the caller decides what to do with AIUnavailableException.
 */
class AIGateway
{
    public const MAX_ATTEMPTS = 2;

    public function __construct(
        private readonly AIProvider $provider,
        private readonly ModelRouter $router,
        private readonly PromptRegistry $prompts,
        private readonly CostCalculator $costs,
    ) {}

    /** @return array{response: StructuredResponse, request_id: int, prompt: PromptTemplate} */
    public function interpret(User $user, string $userContent, ?string $whatsappMessageId = null, ?string $model = null): array
    {
        return $this->run($user, TransactionParser::NAME, TransactionParser::schema(), $userContent, $whatsappMessageId, $model);
    }

    /** Read a receipt photo. The image goes to the model once and is not stored anywhere (only the extracted text is kept, encrypted). */
    public function readReceipt(User $user, string $caption, string $imageBytes, string $mime, ?string $whatsappMessageId = null): array
    {
        return $this->run($user, ReceiptParser::NAME, ReceiptParser::schema(), $caption, $whatsappMessageId, null, [['mime' => $mime, 'data' => base64_encode($imageBytes)]]);
    }

    /**
     * @param  list<array{mime: string, data: string}>  $images
     * @return array{response: StructuredResponse, request_id: int, prompt: PromptTemplate}
     */
    private function run(User $user, string $promptName, array $schema, string $userContent, ?string $whatsappMessageId, ?string $model, array $images = []): array
    {
        $prompt = $this->prompts->active($promptName);
        $model ??= $this->router->primary($promptName);
        $request = new StructuredRequest($model, $prompt->body, $userContent, $schema, (int) config('ai.max_output_tokens'), $images);

        $lastError = null;
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = $this->provider->structured($request);
            } catch (AITransientException $e) {
                $lastError = $e;
                $this->record($user, $whatsappMessageId, $prompt, $model, $attempt, null, 'failed', $e->errorCode, $userContent, $promptName);
                $this->backoff();

                continue;
            } catch (AIPermanentException $e) {
                $this->record($user, $whatsappMessageId, $prompt, $model, $attempt, null, 'failed', $e->errorCode, $userContent, $promptName);
                throw new AIUnavailableException('AI request rejected: '.($e->errorCode ?? 'permanent'), $e->errorCode);
            }

            $id = $this->record($user, $whatsappMessageId, $prompt, $model, $attempt, $response, $response->status, null, $userContent, $promptName);

            return ['response' => $response, 'request_id' => $id, 'prompt' => $prompt];
        }

        Log::warning('ai.unavailable', ['model' => $model, 'error' => $lastError?->errorCode]);
        throw new AIUnavailableException('AI provider unavailable after '.self::MAX_ATTEMPTS.' attempts', $lastError?->errorCode);
    }

    public function setOutcome(int $requestId, string $outcome): void
    {
        AiRequest::whereKey($requestId)->update(['outcome' => $outcome]);
    }

    /** Has this user already used up today's AI allowance? (protects cost; docs/security.md rate limiting) */
    public function overDailyLimit(User $user): bool
    {
        return AiRequest::where('user_id', $user->id)->where('created_at', '>=', now()->startOfDay())
            ->count() >= (int) config('ai.limits.user_requests_per_day');
    }

    private function record(User $user, ?string $messageId, PromptTemplate $prompt, string $model, int $attempt, ?StructuredResponse $r, string $status, ?string $errorCode, string $input, string $requestType): int
    {
        $cost = $r ? $this->costs->estimate($this->provider->pricingProvider(), $r) : ['micros' => 0, 'currency' => 'USD'];

        return AiRequest::create([
            'user_id' => $user->id,
            'whatsapp_message_id' => $messageId,
            'provider' => $this->provider->name(),
            'model' => $r->model ?? $model,
            'request_type' => $requestType,
            'prompt_template_id' => $prompt->id,
            'attempt' => $attempt,
            'input_tokens' => $r->inputTokens ?? 0,
            'output_tokens' => $r->outputTokens ?? 0,
            'cache_read_tokens' => $r->cacheReadTokens ?? 0,
            'cache_write_tokens' => $r->cacheWriteTokens ?? 0,
            'total_tokens' => $r ? $r->inputTokens + $r->outputTokens + $r->cacheReadTokens + $r->cacheWriteTokens : 0,
            'estimated_cost_micros' => $cost['micros'],
            'currency' => $cost['currency'],
            'latency_ms' => $r?->latencyMs,
            'status' => $status,
            'error_code' => $errorCode,
            'provider_request_id' => $r?->requestId,
            'input' => $input,
            'output' => $r?->rawText,
        ])->id;
    }

    private function backoff(): void
    {
        usleep(((int) config('ai.retry_backoff_ms', 400)) * 1000);
    }
}
