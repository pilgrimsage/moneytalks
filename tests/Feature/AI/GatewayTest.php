<?php

use App\Models\AiModelPrice;
use App\Models\AiRequest;
use App\Models\PromptTemplate;
use App\Services\AI\AIGateway;
use App\Services\AI\CostCalculator;
use App\Services\AI\DTO\StructuredResponse;
use App\Services\AI\Exceptions\AIPermanentException;
use App\Services\AI\Exceptions\AITransientException;
use App\Services\AI\Exceptions\AIUnavailableException;
use App\Services\AI\FakeAIProvider;
use App\Services\AI\ModelRouter;
use App\Services\AI\PromptRegistry;
use App\Services\AI\Prompts\TransactionParser;

beforeEach(function () {
    FakeAIProvider::reset();
    $this->user = ledgerUser();
    $this->gateway = app(AIGateway::class);
});

function usage(array $o = []): StructuredResponse
{
    return new StructuredResponse(...($o + [
        'data' => ['language' => 'en', 'items' => []], 'rawText' => '{"language":"en","items":[]}',
        'model' => 'claude-haiku-4-5', 'status' => 'ok', 'inputTokens' => 1000, 'outputTokens' => 100, 'requestId' => 'msg_x', 'latencyMs' => 321,
    ]));
}

describe('recording', function () {
    it('records every call with tokens, cost, latency, prompt version and model', function () {
        FakeAIProvider::respond(usage());

        $r = $this->gateway->interpret($this->user, 'hello', 'wamsg1');

        $row = AiRequest::findOrFail($r['request_id']);
        expect($row->user_id)->toBe($this->user->id)
            ->and($row->whatsapp_message_id)->toBe('wamsg1')
            ->and($row->provider)->toBe('fake')
            ->and($row->model)->toBe('claude-haiku-4-5')
            ->and($row->request_type)->toBe('transaction_parser')
            ->and($row->input_tokens)->toBe(1000)->and($row->output_tokens)->toBe(100)->and($row->total_tokens)->toBe(1100)
            // 1000 in-tokens at $1/MTok = 1000 micro-dollars; 100 out-tokens at $5/MTok = 500
            ->and($row->estimated_cost_micros)->toBe(1500)->and($row->currency)->toBe('USD')
            ->and($row->latency_ms)->toBe(321)
            ->and($row->status)->toBe('ok')
            ->and($row->prompt_template_id)->toBe($r['prompt']->id)
            ->and($row->attempt)->toBe(1);
    });

    it('stores prompt and response bodies encrypted at rest', function () {
        FakeAIProvider::respond(usage(['rawText' => '{"secret":"salary 45000"}']));

        $this->gateway->interpret($this->user, 'my salary is 45000 rupees');

        $raw = DB::table('ai_requests')->first();
        expect($raw->input)->not->toContain('salary')->and($raw->output)->not->toContain('salary')
            ->and(AiRequest::first()->makeVisible(['input', 'output'])->input)->toBe('my salary is 45000 rupees');
    });

    it('sends the active prompt, the schema and the configured model', function () {
        FakeAIProvider::respond(usage());

        $this->gateway->interpret($this->user, 'content');

        $req = FakeAIProvider::$requests[0];
        expect($req->model)->toBe('claude-haiku-4-5')
            ->and($req->system)->toBe(TransactionParser::system())
            ->and($req->schema)->toBe(TransactionParser::schema())
            ->and($req->user)->toBe('content')
            ->and($req->maxTokens)->toBe(1024);
    });

    it('records non-ok answers (refusal, truncation) as such, without throwing', function (string $status) {
        FakeAIProvider::respond(usage(['data' => null, 'rawText' => '', 'status' => $status]));

        $r = $this->gateway->interpret($this->user, 'x');

        expect($r['response']->isOk())->toBeFalse()->and(AiRequest::first()->status)->toBe($status);
    })->with(['refusal', 'truncated', 'invalid_json']);
});

describe('retries and failure', function () {
    it('retries once on a transient error and records both attempts', function () {
        FakeAIProvider::respond(new AITransientException('429', 'http_429'));
        FakeAIProvider::respond(usage());

        $r = $this->gateway->interpret($this->user, 'x');

        $rows = AiRequest::orderBy('id')->get();
        expect($r['response']->isOk())->toBeTrue()
            ->and($rows->pluck('status')->all())->toBe(['failed', 'ok'])
            ->and($rows->pluck('attempt')->all())->toBe([1, 2])
            ->and($rows[0]->error_code)->toBe('http_429')
            ->and($rows[0]->estimated_cost_micros)->toBe(0);   // a failed call costs nothing
    });

    it('gives up after two transient failures with a typed exception', function () {
        FakeAIProvider::respond(new AITransientException('503', 'http_503'));
        FakeAIProvider::respond(new AITransientException('503', 'http_503'));

        expect(fn () => $this->gateway->interpret($this->user, 'x'))->toThrow(AIUnavailableException::class)
            ->and(AiRequest::count())->toBe(2);
    });

    it('does not retry permanent errors', function () {
        FakeAIProvider::respond(new AIPermanentException('bad key', 'auth'));
        FakeAIProvider::respond(usage());   // must never be consumed

        expect(fn () => $this->gateway->interpret($this->user, 'x'))->toThrow(AIUnavailableException::class)
            ->and(AiRequest::count())->toBe(1)
            ->and(FakeAIProvider::$requests)->toHaveCount(1);
    });
});

describe('model routing', function () {
    it('uses the fast model by default and an explicit override when asked', function () {
        FakeAIProvider::respond(usage());
        FakeAIProvider::respond(usage(['model' => 'claude-sonnet-5-5']));

        $this->gateway->interpret($this->user, 'x');
        $this->gateway->interpret($this->user, 'x', null, 'claude-sonnet-5-5');

        expect(collect(FakeAIProvider::$requests)->pluck('model')->all())->toBe(['claude-haiku-4-5', 'claude-sonnet-5-5']);
    });

    it('reads model ids from config, never from code', function () {
        config(['ai.models.fast' => 'some-future-model']);
        FakeAIProvider::respond(usage());

        $this->gateway->interpret($this->user, 'x');

        expect(FakeAIProvider::$requests[0]->model)->toBe('some-future-model');
    });

    it('only offers an escalation model when enabled and configured', function () {
        $router = app(ModelRouter::class);
        expect($router->escalation('transaction_parser'))->toBeNull();

        config(['ai.models.strong' => 'claude-sonnet-5-5']);
        expect($router->escalation('transaction_parser'))->toBeNull();       // configured but not enabled

        config(['ai.escalation_enabled' => true]);
        expect($router->escalation('transaction_parser'))->toBe('claude-sonnet-5-5');

        config(['ai.models.strong' => 'claude-haiku-4-5']);
        expect($router->escalation('transaction_parser'))->toBeNull();       // pointless: same as primary
    });
});

describe('limits', function () {
    it('flags a user who used up the daily allowance', function () {
        config(['ai.limits.user_requests_per_day' => 3]);
        FakeAIProvider::responder(fn () => usage());

        foreach (range(1, 2) as $i) {
            $this->gateway->interpret($this->user, 'x');
        }
        expect($this->gateway->overDailyLimit($this->user))->toBeFalse();

        $this->gateway->interpret($this->user, 'x');
        expect($this->gateway->overDailyLimit($this->user))->toBeTrue()
            ->and($this->gateway->overDailyLimit(ledgerUser('919111111111')))->toBeFalse();
    });
});

describe('cost calculation', function () {
    beforeEach(fn () => $this->cost = app(CostCalculator::class));

    it('prices from the usage the provider reported, rounding up to whole micro-dollars', function () {
        $r = usage(['inputTokens' => 1, 'outputTokens' => 1]);

        // 1 token at $1/MTok = 1 micro-dollar; 1 token at $5/MTok = 5
        expect($this->cost->estimate('anthropic', $r)['micros'])->toBe(6)
            ->and($this->cost->estimate('anthropic', usage(['inputTokens' => 0, 'outputTokens' => 0]))['micros'])->toBe(0);
    });

    it('prices cache reads and writes at their own rates', function () {
        $r = usage(['inputTokens' => 0, 'outputTokens' => 0, 'cacheReadTokens' => 1_000_000, 'cacheWriteTokens' => 1_000_000]);

        // Haiku: cache read $0.10/MTok, 5-minute cache write $1.25/MTok
        expect($this->cost->estimate('anthropic', $r)['micros'])->toBe(100_000 + 1_250_000);
    });

    it('prices a dated model id as its family and other models from their own row', function () {
        expect($this->cost->estimate('anthropic', usage(['model' => 'claude-haiku-4-5-20251001', 'inputTokens' => 1_000_000, 'outputTokens' => 0]))['micros'])->toBe(1_000_000)
            ->and($this->cost->estimate('anthropic', usage(['model' => 'claude-sonnet-5-5', 'inputTokens' => 1_000_000, 'outputTokens' => 0]))['micros'])->toBe(2_000_000);
    });

    it('reports unknown models as unpriced instead of guessing', function () {
        $e = $this->cost->estimate('anthropic', usage(['model' => 'mystery-model']));

        expect($e['priced'])->toBeFalse()->and($e['micros'])->toBe(0);
    });

    it('uses the price that was effective when the request ran', function () {
        AiModelPrice::create(['provider' => 'anthropic', 'model' => 'claude-haiku-4-5', 'input_micros_per_mtok' => 2_000_000, 'output_micros_per_mtok' => 10_000_000,
            'cache_read_micros_per_mtok' => 0, 'cache_write_micros_per_mtok' => 0, 'effective_from' => '2027-01-01']);
        $r = usage(['inputTokens' => 1_000_000, 'outputTokens' => 0]);

        expect($this->cost->estimate('anthropic', $r, now())['micros'])->toBe(1_000_000)
            ->and($this->cost->estimate('anthropic', $r, now()->addYear()->addDay())['micros'])->toBe(2_000_000);
    });
});

describe('prompt registry', function () {
    it('syncs the built-in prompt once and returns the same active version', function () {
        $a = app(PromptRegistry::class)->active();
        $b = app(PromptRegistry::class)->active();

        expect($a->id)->toBe($b->id)->and($a->version)->toBe(TransactionParser::VERSION)->and($a->status)->toBe('active')
            ->and($a->body)->toBe(TransactionParser::system())->and(PromptTemplate::count())->toBe(1);
    });

    it('lets an activated newer version in the table win over the built-in', function () {
        app(PromptRegistry::class)->active();   // the built-in prompt syncs on first use...
        PromptTemplate::create(['name' => 'transaction_parser', 'version' => 'v99', 'status' => 'active', 'body' => 'NEW PROMPT']);

        FakeAIProvider::respond(usage());
        $this->gateway->interpret($this->user, 'x');

        expect(FakeAIProvider::$requests[0]->system)->toBe('NEW PROMPT');
    });
});

it('retires the previous built-in version when a new built-in version ships', function () {
    PromptTemplate::create(['name' => 'transaction_parser', 'version' => 'v1', 'status' => 'active', 'source' => 'builtin', 'body' => 'OLD BUILT-IN']);

    $active = app(PromptRegistry::class)->active();

    expect($active->version)->toBe(TransactionParser::VERSION)->and($active->body)->toBe(TransactionParser::system())
        ->and(PromptTemplate::where('version', 'v1')->first()->status)->toBe('retired');
});
