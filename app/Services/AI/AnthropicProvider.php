<?php

namespace App\Services\AI;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\NotFoundException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use App\Services\AI\DTO\StructuredRequest;
use App\Services\AI\DTO\StructuredResponse;
use App\Services\AI\Exceptions\AIPermanentException;
use App\Services\AI\Exceptions\AITransientException;
use Throwable;

/**
 * Claude via the official Anthropic PHP SDK. Uses structured outputs (`output_config.format`),
 * which works on every current model including Haiku 4.5; forced tool use would not (it is rejected by
 * newer models). Refusals and truncation come back as a non-ok StructuredResponse, never as guessed data.
 */
class AnthropicProvider implements AIProvider
{
    public function __construct(private ?Client $client = null) {}

    public function name(): string
    {
        return 'anthropic';
    }

    public function pricingProvider(): string
    {
        return 'anthropic';
    }

    public function structured(StructuredRequest $request): StructuredResponse
    {
        $client = $this->client ??= $this->makeClient();
        $started = hrtime(true);

        try {
            $message = $client->messages->create(
                model: $request->model,
                maxTokens: $request->maxTokens,
                system: [['type' => 'text', 'text' => $request->system, 'cacheControl' => ['type' => 'ephemeral']]],
                messages: [['role' => 'user', 'content' => $this->content($request)]],
                outputConfig: ['format' => ['type' => 'json_schema', 'schema' => $request->schema]],
                temperature: config('ai.temperature'),
            );
        } catch (Throwable $e) {
            throw $this->classify($e);
        }

        $latency = (int) ((hrtime(true) - $started) / 1_000_000);

        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        $usage = $message->usage;
        $status = match ($message->stopReason) {
            'refusal' => 'refusal',
            'max_tokens' => 'truncated',
            default => 'ok',
        };
        $data = null;
        if ($status === 'ok') {
            $decoded = json_decode($text, true);
            if (is_array($decoded)) {
                $data = $decoded;
            } else {
                $status = 'invalid_json';
            }
        }

        return new StructuredResponse(
            data: $data,
            rawText: $text,
            model: $message->model,
            status: $status,
            inputTokens: (int) $usage->inputTokens,
            outputTokens: (int) $usage->outputTokens,
            cacheReadTokens: (int) ($usage->cacheReadInputTokens ?? 0),
            cacheWriteTokens: (int) ($usage->cacheCreationInputTokens ?? 0),
            requestId: $message->id,
            latencyMs: $latency,
        );
    }

    /** Text only, or image blocks followed by the text (vision). */
    private function content(StructuredRequest $request): string|array
    {
        if ($request->images === []) {
            return $request->user;
        }

        return [
            ...array_map(fn (array $img) => ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $img['mime'], 'data' => $img['data']]], $request->images),
            ['type' => 'text', 'text' => $request->user],
        ];
    }

    private function classify(Throwable $e): Throwable
    {
        if ($e instanceof APIConnectionException) { // includes timeouts
            return new AITransientException('Network error: '.$e->getMessage(), 'connection');
        }
        if ($e instanceof APIStatusException) {
            $status = (int) $e->status;
            // 408/409/429 and every 5xx (incl. Anthropic's 529 overloaded) are worth retrying.
            if (in_array($status, [408, 409, 429], true) || $status >= 500) {
                return new AITransientException("Anthropic HTTP {$status}", 'http_'.$status);
            }
            if ($e instanceof AuthenticationException || $e instanceof PermissionDeniedException) {
                return new AIPermanentException('Anthropic credentials rejected', 'auth');
            }
            if ($e instanceof NotFoundException) {
                return new AIPermanentException('Model not found', 'model_not_found');
            }
            if ($e instanceof BadRequestException) {
                return new AIPermanentException('Anthropic rejected the request', 'bad_request');
            }

            return new AIPermanentException("Anthropic HTTP {$status}", 'http_'.$status);
        }

        return new AIPermanentException(get_class($e), 'unexpected'); // never include request data here
    }

    private function makeClient(): Client
    {
        $key = (string) config('ai.anthropic.api_key');
        if ($key === '') {
            throw new AIPermanentException('ANTHROPIC_API_KEY is not set.', 'no_api_key');
        }

        return new Client(apiKey: $key, requestOptions: [
            'timeout' => (float) config('ai.anthropic.timeout_seconds'),
            'maxRetries' => (int) config('ai.anthropic.max_retries'),
        ]);
    }
}
