<?php

use App\Enums\TransactionType;
use App\Models\AiRequest;
use App\Models\LedgerTransaction;
use App\Services\AI\FakeAIProvider;
use App\Services\Ops\AiSwitch;
use App\Services\Ops\HealthCheck;
use App\Services\Speech\SpeechToTextProvider;
use App\Services\WhatsApp\Testing\FakeWhatsAppProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    Cache::flush();
    useInterpretationHandler();
    FakeWhatsAppProvider::reset();
    FakeAIProvider::reset();
    config(['whatsapp.outbound.retry_backoff_ms' => [0, 0]]);
    $this->user = ledgerUser('919876543210');
    $this->status = fn () => collect(app(HealthCheck::class)->run())->keyBy('name');
});

describe('health checks', function () {
    it('fails the scheduler and ledger checks until their heartbeats exist, and passes once they do', function () {
        $c = ($this->status)();
        expect($c['scheduler']['ok'])->toBeFalse()->and($c['ledger verification']['ok'])->toBeFalse()->and($c['database']['ok'])->toBeTrue()
            ->and(app(HealthCheck::class)->healthy())->toBeFalse();

        Cache::put(HealthCheck::HEARTBEAT_KEY, now(), now()->addDay());
        $this->artisan('moneytalks:ledger:verify')->assertSuccessful();

        expect(app(HealthCheck::class)->healthy())->toBeTrue()->and(($this->status)()['ledger verification']['detail'])->toContain('OK');
    });

    it('notices a stale heartbeat and a failed ledger verification', function () {
        Cache::put(HealthCheck::HEARTBEAT_KEY, now()->subMinutes(30), now()->addDay());
        Cache::put(HealthCheck::LEDGER_KEY, ['ok' => false, 'at' => now()], now()->addDay());

        $c = ($this->status)();
        expect($c['scheduler']['ok'])->toBeFalse()->and($c['scheduler']['detail'])->toContain('30 min')->and($c['ledger verification']['detail'])->toContain('FAILED');
    });

    it('flags stuck webhook events and old queued jobs as critical, and failures as warnings', function () {
        DB::table('webhook_events')->insert(['provider' => 'meta', 'event_hash' => str_repeat('a', 64), 'payload' => '{}', 'status' => 'received', 'received_at' => now()->subHour()]);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => time() - 3600, 'created_at' => time() - 3600]);

        $c = ($this->status)();
        expect($c['webhook events']['ok'])->toBeFalse()->and($c['webhook events']['critical'])->toBeTrue()
            ->and($c['queue']['ok'])->toBeFalse()->and($c['failed jobs']['critical'])->toBeFalse();
    });

    it('warns about failed outbound messages and a failing AI', function () {
        DB::table('whatsapp_messages')->insert(['id' => (string) Str::ulid(), 'direction' => 'out', 'message_type' => 'text', 'status' => 'failed', 'created_at' => now(), 'updated_at' => now()]);
        foreach (range(1, 6) as $i) {
            AiRequest::create(['user_id' => $this->user->id, 'provider' => 'fake', 'model' => 'm', 'request_type' => 'transaction_parser', 'attempt' => 1, 'status' => 'failed']);
        }

        $c = ($this->status)();
        expect($c['outbound messages']['ok'])->toBeFalse()->and($c['AI']['ok'])->toBeFalse()->and($c['AI']['detail'])->toContain('6 of 6');
    });
});

describe('GET /health', function () {
    it('is off (404) without a token configured, rejects a wrong token, and shows only names and pass/fail', function () {
        $this->getJson('/health')->assertNotFound();

        config(['moneytalks.health_token' => 'secret-token']);
        $this->getJson('/health')->assertUnauthorized();
        $this->getJson('/health', ['Authorization' => 'Bearer nope'])->assertUnauthorized();

        $r = $this->getJson('/health', ['Authorization' => 'Bearer secret-token']);
        $r->assertStatus(503)->assertJsonPath('status', 'failing')->assertJsonStructure(['checks' => [['name', 'ok']]]);
        expect(json_encode($r->json()))->not->toContain('min ago')->and(json_encode($r->json()))->not->toContain('waiting');

        Cache::put(HealthCheck::HEARTBEAT_KEY, now(), now()->addDay());
        $this->artisan('moneytalks:ledger:verify');
        $this->getJson('/health', ['Authorization' => 'Bearer secret-token'])->assertOk()->assertJsonPath('status', 'ok');
    });
});

describe('the AI kill switch', function () {
    it('stops model calls but keeps balance and undo working, and resumes cleanly', function () {
        $this->artisan('moneytalks:ai:pause', ['reason' => 'testing'])->assertSuccessful();
        FakeAIProvider::respond(aiEnvelope(aiItem()));

        waText($this, 'spent 250 on vegetables');
        waText($this, 'balance');

        expect(sentTexts()[0])->toContain('Smart reading is paused')->and(FakeAIProvider::$requests)->toBe([])->and(LedgerTransaction::count())->toBe(0)
            ->and(sentTexts()[1])->toContain('Balances')->and(($this->status)()['AI']['detail'])->toContain('PAUSED (testing)');

        $this->artisan('moneytalks:ai:resume')->assertSuccessful();
        waText($this, 'spent 250 on vegetables');

        expect(LedgerTransaction::count())->toBe(1);
    });

    it('blocks voice notes and photos while paused, before downloading anything', function () {
        config(['stt.provider' => 'fake']);
        app()->forgetInstance(SpeechToTextProvider::class);
        FakeWhatsAppProvider::$media['V'] = ['OggS-audio', 'audio/ogg'];
        app(AiSwitch::class)->pause('test');

        postWebhook($this, waFactory()->media('919876543210', 'audio', 'V'))->assertOk();

        expect(sentTexts()[0])->toContain('paused');
    });

    it('closes by itself when the global daily budget is used up', function () {
        config(['ai.limits.global_daily_budget_micros' => 1_000_000]);
        expect(app(AiSwitch::class)->blocked())->toBeFalse();

        AiRequest::create(['user_id' => $this->user->id, 'provider' => 'fake', 'model' => 'm', 'request_type' => 'transaction_parser', 'attempt' => 1, 'status' => 'ok', 'estimated_cost_micros' => 1_500_000]);
        Cache::flush();

        expect(app(AiSwitch::class)->blocked())->toBeTrue()->and(app(AiSwitch::class)->overBudget())->toBeTrue();
    });
});

describe('cost report', function () {
    it('summarises AI cost by feature and per transaction', function () {
        AiRequest::create(['user_id' => $this->user->id, 'provider' => 'fake', 'model' => 'm', 'request_type' => 'transaction_parser', 'attempt' => 1, 'status' => 'ok', 'estimated_cost_micros' => 2000]);
        AiRequest::create(['user_id' => $this->user->id, 'provider' => 'fake', 'model' => 'm', 'request_type' => 'receipt_parser', 'attempt' => 1, 'status' => 'ok', 'estimated_cost_micros' => 6000]);
        ledger()->post(command($this->user, TransactionType::Expense, '100', ['categoryId' => category($this->user, 'Fuel')->id]));

        $this->artisan('moneytalks:cost:report', ['--days' => 7])
            ->expectsOutputToContain('AI $0.0080; 1 transactions recorded')->expectsOutputToContain('AI cost per recorded transaction: $0.0080')
            ->assertSuccessful();
    });
});

describe('database triggers check', function () {
    it('reports whether the ledger immutability triggers are installed', function () {
        $c = ($this->status)()['ledger triggers'];

        expect($c['critical'])->toBeFalse()->and($c['ok'])->toBe(dbHasLedgerTriggers());
    });
});
