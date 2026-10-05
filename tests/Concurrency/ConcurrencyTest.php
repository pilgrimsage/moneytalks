<?php

use App\Domain\Ledger\LedgerVerifier;
use App\Enums\TransactionType;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\WebhookEvent;
use App\Models\WhatsappMessage;

/**
 * Real parallel PHP processes against the real database (no wrapping test transaction, so
 * child processes see the data). Proves the user-row lock + idempotency keys hold under races.
 */

/** Run $jobs as simultaneous child processes; returns their decoded JSON outputs. */
function race(array $jobs): array
{
    $startAt = microtime(true) + 3.0; // generous: lets every child finish booting first
    $procs = [];
    $env = array_merge(getenv(), [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => config('database.default'),
        'DB_HOST' => config('database.connections.mysql.host'),
        'DB_PORT' => (string) config('database.connections.mysql.port'),
        'DB_DATABASE' => DB::getDatabaseName(),
        'DB_USERNAME' => config('database.connections.mysql.username'),
        'DB_PASSWORD' => config('database.connections.mysql.password'),
        'DB_URL' => '',
        'PII_BLIND_INDEX_KEY' => config('moneytalks.blind_index_key') ?? 'testing-only-key',
        'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array',
    ]);

    foreach ($jobs as $i => $job) {
        $cmd = [PHP_BINARY, __DIR__.'/worker.php', json_encode($job + ['start_at' => $startAt])];
        $procs[$i] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], null, $env);
    }

    $out = [];
    foreach ($procs as $i => $p) {
        $stdout = stream_get_contents($pipes[$i][1]);
        $stderr = stream_get_contents($pipes[$i][2]);
        proc_close($p);
        $decoded = json_decode($stdout, true);
        expect($decoded)->not->toBeNull("worker {$i} produced no JSON. stdout: {$stdout} stderr: {$stderr}");
        $out[] = $decoded;
    }

    return $out;
}

beforeEach(function () {
    $this->user = ledgerUser();
    $this->base = ['user' => $this->user->id, 'account' => account($this->user, 'Cash')->id, 'category' => category($this->user, 'Groceries')->id];
});

it('posts exactly once when the same webhook is delivered by 8 processes at the same instant', function () {
    $jobs = array_fill(0, 8, $this->base + ['op' => 'post', 'minor' => 12345, 'key' => 'wamid.RACE:0']);

    $results = race($jobs);

    expect(collect($results)->every(fn ($r) => $r['ok']))->toBeTrue(json_encode($results))
        ->and(collect($results)->pluck('id')->unique())->toHaveCount(1)
        ->and(collect($results)->where('replayed', false))->toHaveCount(1)
        ->and(LedgerTransaction::count())->toBe(1)
        ->and(LedgerEntry::count())->toBe(2)
        ->and(balanceOf(account($this->user, 'Cash')))->toBe(-12345);
});

it('keeps balances exact when 10 different expenses are posted in parallel', function () {
    $jobs = [];
    foreach (range(1, 10) as $i) {
        $jobs[] = $this->base + ['op' => 'post', 'minor' => $i * 100, 'key' => "wamid.PAR:{$i}"];
    }

    $results = race($jobs);

    expect(collect($results)->every(fn ($r) => $r['ok']))->toBeTrue(json_encode($results))
        ->and(LedgerTransaction::count())->toBe(10)
        ->and(balanceOf(account($this->user, 'Cash')))->toBe(-5500)           // 100+200+...+1000
        ->and(balanceOf(account($this->user, 'Expenses')))->toBe(5500)
        ->and(app(LedgerVerifier::class)->verify())->toBe([]);
});

it('reverses a transaction only once when 6 processes race to undo it', function () {
    $tx = ledger()->post(command($this->user, TransactionType::Expense, '500', ['categoryId' => $this->base['category']]))->transaction;

    $results = race(array_fill(0, 6, $this->base + ['op' => 'reverse', 'target' => $tx->id, 'minor' => 0, 'key' => 'x']));

    expect(collect($results)->every(fn ($r) => $r['ok']))->toBeTrue(json_encode($results))
        ->and(collect($results)->pluck('id')->unique())->toHaveCount(1)
        ->and(LedgerTransaction::where('type', 'reversal')->count())->toBe(1)
        ->and(balanceOf(account($this->user, 'Cash')))->toBe(0)
        ->and(app(LedgerVerifier::class)->verify())->toBe([]);
});

it('applies a racing correction exactly once; competing corrections are refused', function () {
    $tx = ledger()->post(command($this->user, TransactionType::Expense, '500', ['categoryId' => $this->base['category']]))->transaction;

    $jobs = [];
    foreach ([600, 700, 800, 900] as $i => $rupees) {
        $jobs[] = $this->base + ['op' => 'correct', 'target' => $tx->id, 'minor' => $rupees * 100, 'key' => "fix:{$i}"];
    }

    $results = race($jobs);
    $ok = collect($results)->where('ok', true);

    expect($ok)->toHaveCount(1)                                              // one winner...
        ->and(collect($results)->where('ok', false)->pluck('error')->unique()->all())
        ->toBe(['App\Domain\Ledger\Exceptions\LedgerException'])             // ...the rest cleanly refused
        ->and(LedgerTransaction::count())->toBe(3)                           // original, reversal, one replacement
        ->and(-balanceOf(account($this->user, 'Cash')))->toBeIn([60000, 70000, 80000, 90000])
        ->and(app(LedgerVerifier::class)->verify())->toBe([]);
});

describe('webhook processing', function () {
    function storeEvent(array $payload): int
    {
        return WebhookEvent::create([
            'provider' => 'fake', 'event_hash' => hash('sha256', json_encode($payload).bin2hex(random_bytes(4))),
            'payload' => json_encode($payload), 'status' => 'received', 'received_at' => now(),
        ])->id;
    }

    it('processes one event exactly once when 6 workers claim it simultaneously', function () {
        $id = storeEvent(waFactory()->text('919876543210', 'spent 250', 'wamid.CONC1'));

        $results = race(array_fill(0, 6, ['op' => 'event', 'event_id' => $id]));

        expect(collect($results)->every(fn ($r) => $r['ok']))->toBeTrue(json_encode($results))
            ->and(WebhookEvent::find($id)->attempts)->toBe(1)             // one claim won
            ->and(WebhookEvent::find($id)->status)->toBe('processed')
            ->and(WhatsappMessage::where('direction', 'in')->count())->toBe(1)
            ->and(WhatsappMessage::where('direction', 'out')->count())->toBe(1);
    });

    it('answers one message once even when it arrives in 6 different deliveries at the same instant', function () {
        // Same WhatsApp message id, six distinct raw payloads (so six webhook_events rows).
        $ids = [];
        foreach (range(1, 6) as $i) {
            $p = waFactory()->text('919876543210', 'spent 250', 'wamid.CONC2');
            $p['entry'][0]['changes'][0]['value']['contacts'][0]['profile']['name'] = "variant {$i}";
            $ids[] = storeEvent($p);
        }

        $results = race(array_map(fn ($id) => ['op' => 'event', 'event_id' => $id], $ids));

        expect(collect($results)->every(fn ($r) => $r['ok']))->toBeTrue(json_encode($results))
            ->and(WhatsappMessage::where('wa_message_id', 'wamid.CONC2')->count())->toBe(1)
            ->and(WhatsappMessage::where('direction', 'out')->count())->toBe(1)
            ->and(WhatsappMessage::where('direction', 'out')->first()->status->value)->toBe('sent');
    });
});
