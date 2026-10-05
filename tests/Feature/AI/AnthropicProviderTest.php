<?php

use Anthropic\Client;
use App\Services\AI\AnthropicProvider;
use App\Services\AI\DTO\StructuredRequest;
use App\Services\AI\Exceptions\AIPermanentException;
use App\Services\AI\Exceptions\AITransientException;
use App\Services\AI\Prompts\ReceiptParser;
use App\Services\AI\Prompts\TransactionParser;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response;

/** Build the REAL Anthropic SDK client on top of a mocked HTTP transport: no network, real request building. */
function sdkWith(array $responses, array &$history): AnthropicProvider
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $client = new Client(apiKey: 'sk-test-key', requestOptions: ['transporter' => new Guzzle(['handler' => $stack, 'http_errors' => false]), 'maxRetries' => 0]);

    return new AnthropicProvider($client);
}

function messageJson(string $text, string $stop = 'end_turn', array $usage = []): Response
{
    return new Response(200, ['Content-Type' => 'application/json', 'request-id' => 'req_1'], json_encode([
        'id' => 'msg_01ABC', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-4-5-20251001',
        'content' => [['type' => 'text', 'text' => $text]], 'stop_reason' => $stop, 'stop_sequence' => null,
        'usage' => $usage + ['input_tokens' => 1234, 'output_tokens' => 156, 'cache_read_input_tokens' => 1000, 'cache_creation_input_tokens' => 0],
    ]));
}

function errorJson(int $status, string $type = 'api_error'): Response
{
    return new Response($status, ['Content-Type' => 'application/json'], json_encode(['type' => 'error', 'error' => ['type' => $type, 'message' => 'secret detail sk-test-key']]));
}

beforeEach(function () {
    $this->history = [];
    $this->request = new StructuredRequest('claude-haiku-4-5', 'SYSTEM PROMPT', '<user_message>spent 250</user_message>', TransactionParser::schema(), 512);
    config(['ai.temperature' => 0.0]);
});

it('sends a structured-output request in the documented shape', function () {
    $provider = sdkWith([messageJson('{"language":"en","items":[]}')], $this->history);

    $provider->structured($this->request);

    /** @var Psr7Request $sent */
    $sent = $this->history[0]['request'];
    $body = json_decode((string) $sent->getBody(), true);

    expect($sent->getMethod())->toBe('POST')
        ->and($sent->getUri()->getPath())->toBe('/v1/messages')
        ->and($sent->getHeaderLine('x-api-key'))->toBe('sk-test-key')
        ->and($sent->getHeaderLine('anthropic-version'))->toBe('2023-06-01')
        ->and($body['model'])->toBe('claude-haiku-4-5')
        ->and($body['max_tokens'])->toBe(512)
        ->and($body['messages'])->toBe([['role' => 'user', 'content' => '<user_message>spent 250</user_message>']])
        ->and($body['system'][0]['text'])->toBe('SYSTEM PROMPT')
        ->and($body['system'][0]['cache_control'])->toBe(['type' => 'ephemeral'])
        ->and($body['output_config']['format']['type'])->toBe('json_schema')
        ->and($body['output_config']['format']['schema'])->toBe(TransactionParser::schema())
        ->and($body['temperature'])->toEqual(0)
        ->and($body)->not->toHaveKeys(['tools', 'tool_choice', 'thinking']);   // forced tool use / thinking are not used
});

it('omits temperature when it is configured as null (models that reject sampling params)', function () {
    config(['ai.temperature' => null]);
    $provider = sdkWith([messageJson('{"language":"en","items":[]}')], $this->history);

    $provider->structured($this->request);

    expect(json_decode((string) $this->history[0]['request']->getBody(), true))->not->toHaveKey('temperature');
});

it('parses the JSON answer and the usage numbers', function () {
    $provider = sdkWith([messageJson('{"language":"hinglish","items":[]}')], $this->history);

    $r = $provider->structured($this->request);

    expect($r->isOk())->toBeTrue()
        ->and($r->data)->toBe(['language' => 'hinglish', 'items' => []])
        ->and($r->model)->toBe('claude-haiku-4-5-20251001')
        ->and($r->inputTokens)->toBe(1234)->and($r->outputTokens)->toBe(156)
        ->and($r->cacheReadTokens)->toBe(1000)->and($r->cacheWriteTokens)->toBe(0)
        ->and($r->requestId)->toBe('msg_01ABC')
        ->and($r->latencyMs)->toBeGreaterThanOrEqual(0);
});

it('reports refusals, truncation and invalid JSON without ever inventing data', function (string $text, string $stop, string $status) {
    $r = sdkWith([messageJson($text, $stop)], $this->history)->structured($this->request);

    expect($r->status)->toBe($status)->and($r->data)->toBeNull()->and($r->isOk())->toBeFalse();
})->with([
    'refusal' => ['', 'refusal', 'refusal'],
    'truncated' => ['{"language":"en","ite', 'max_tokens', 'truncated'],
    'prose instead of JSON' => ['Sure! Here is the JSON you asked for.', 'end_turn', 'invalid_json'],
    'JSON scalar' => ['42', 'end_turn', 'invalid_json'],
]);

it('classifies retryable failures as transient', function (Response|Throwable $failure) {
    $provider = sdkWith([$failure], $this->history);

    expect(fn () => $provider->structured($this->request))->toThrow(AITransientException::class);
})->with([
    '429 rate limit' => [fn () => errorJson(429, 'rate_limit_error')],
    '500' => [fn () => errorJson(500)],
    '502' => [fn () => errorJson(502)],
    '529 overloaded' => [fn () => errorJson(529, 'overloaded_error')],
    '408 timeout' => [fn () => errorJson(408)],
    'connection failure' => [fn () => new ConnectException('connection refused', new Psr7Request('POST', '/v1/messages'))],
]);

it('classifies request and credential problems as permanent', function (Response $failure, string $code) {
    $provider = sdkWith([$failure], $this->history);

    try {
        $provider->structured($this->request);
        $this->fail('expected an exception');
    } catch (AIPermanentException $e) {
        expect($e->errorCode)->toBe($code);
    }
})->with([
    '401' => [fn () => errorJson(401, 'authentication_error'), 'auth'],
    '403' => [fn () => errorJson(403, 'permission_error'), 'auth'],
    '400' => [fn () => errorJson(400, 'invalid_request_error'), 'bad_request'],
    '404 model' => [fn () => errorJson(404, 'not_found_error'), 'model_not_found'],
]);

it('never leaks the API key or provider error text into exceptions', function () {
    $provider = sdkWith([errorJson(401, 'authentication_error')], $this->history);

    try {
        $provider->structured($this->request);
    } catch (Throwable $e) {
        expect($e->getMessage())->not->toContain('sk-test-key')->and($e->getMessage())->not->toContain('secret detail');
    }
});

it('refuses to run without an API key', function () {
    config(['ai.anthropic.api_key' => '']);

    try {
        (new AnthropicProvider)->structured($this->request);
        $this->fail('expected an exception');
    } catch (AIPermanentException $e) {
        expect($e->errorCode)->toBe('no_api_key');
    }
});

it('sends receipt photos as base64 image blocks before the text (vision)', function () {
    $provider = sdkWith([messageJson('{"is_receipt":true}')], $this->history);
    $request = new StructuredRequest('claude-haiku-4-5', 'SYSTEM', 'caption', ReceiptParser::schema(), 256, [['mime' => 'image/jpeg', 'data' => base64_encode('JPEGBYTES')]]);

    $provider->structured($request);

    $body = json_decode((string) $this->history[0]['request']->getBody(), true);
    expect($body['messages'][0]['content'])->toBe([
        ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode('JPEGBYTES')]],
        ['type' => 'text', 'text' => 'caption'],
    ]);
});
