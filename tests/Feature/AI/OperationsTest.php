<?php

use App\Models\AiRequest;
use App\Services\AI\AIGateway;
use App\Services\AI\DTO\StructuredResponse;
use App\Services\AI\FakeAIProvider;
use Illuminate\Console\Scheduling\Schedule;

beforeEach(function () {
    FakeAIProvider::reset();
    $this->user = ledgerUser();
});

function seedRequest(array $o = []): AiRequest
{
    return AiRequest::create($o + [
        'user_id' => test()->user->id, 'provider' => 'fake', 'model' => 'claude-haiku-4-5', 'request_type' => 'transaction_parser',
        'input_tokens' => 1000, 'output_tokens' => 100, 'total_tokens' => 1100, 'estimated_cost_micros' => 1500, 'status' => 'ok',
        'input' => 'my secret message', 'output' => '{"x":1}', 'outcome' => 'record',
    ]);
}

describe('retention purge', function () {
    it('erases old prompt and response bodies but keeps the token and cost metadata', function () {
        $old = seedRequest();
        $old->forceFill(['created_at' => now()->subDays(40)])->saveQuietly();
        $fresh = seedRequest();

        $this->artisan('moneytalks:ai:purge')->expectsOutputToContain('Purged bodies of 1')->assertSuccessful();

        $oldRaw = DB::table('ai_requests')->find($old->id);
        $freshRaw = DB::table('ai_requests')->find($fresh->id);
        expect($oldRaw->input)->toBeNull()->and($oldRaw->output)->toBeNull()
            ->and($oldRaw->estimated_cost_micros)->toBe(1500)->and($oldRaw->input_tokens)->toBe(1000)->and($oldRaw->outcome)->toBe('record')
            ->and($freshRaw->input)->not->toBeNull();
    });

    it('honours the configured retention and an explicit override, and is safe to repeat', function () {
        $r = seedRequest();
        $r->forceFill(['created_at' => now()->subDays(5)])->saveQuietly();

        $this->artisan('moneytalks:ai:purge')->expectsOutputToContain('Purged bodies of 0');
        config(['ai.retention_days' => 3]);
        $this->artisan('moneytalks:ai:purge')->expectsOutputToContain('Purged bodies of 1');
        $this->artisan('moneytalks:ai:purge')->expectsOutputToContain('Purged bodies of 0');
        $this->artisan('moneytalks:ai:purge', ['--days' => 1])->assertSuccessful();
    });

    it('is scheduled daily', function () {
        $commands = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command)->implode(' ');

        expect($commands)->toContain('moneytalks:ai:purge');
    });
});

describe('usage report', function () {
    it('summarises requests, failures, tokens and cost, and cost per recorded transaction', function () {
        seedRequest();
        seedRequest(['outcome' => 'clarify', 'estimated_cost_micros' => 1500]);
        seedRequest(['status' => 'failed', 'outcome' => null, 'estimated_cost_micros' => 0, 'input_tokens' => 0, 'output_tokens' => 0]);
        seedRequest(['model' => 'claude-sonnet-5-5', 'estimated_cost_micros' => 3000]);

        $this->artisan('moneytalks:ai:usage', ['--days' => 7])
            ->expectsOutputToContain('claude-haiku-4-5')->expectsOutputToContain('claude-sonnet-5-5')
            ->expectsOutputToContain('Total: 4 requests, $0.0060 over 7 day(s); $0.0030 per recorded transaction')
            ->assertSuccessful();
    });

    it('handles an empty period', function () {
        $this->artisan('moneytalks:ai:usage')->expectsOutputToContain('Total: 0 requests')->assertSuccessful();
    });

    it('reflects real gateway calls end to end', function () {
        FakeAIProvider::respond(new StructuredResponse(['language' => 'en', 'items' => []], '{}', 'claude-haiku-4-5', 'ok', 2000, 200, 0, 0, 'm', 5));
        app(AIGateway::class)->interpret($this->user, 'x');

        $this->artisan('moneytalks:ai:usage')->expectsOutputToContain('Total: 1 requests, $0.0030')->assertSuccessful();   // 2000x$1 + 200x$5 per MTok
    });
});
