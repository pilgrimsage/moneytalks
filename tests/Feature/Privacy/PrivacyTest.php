<?php

use App\Domain\Finance\UserProvisioner;
use App\Domain\Ledger\CounterpartyService;
use App\Domain\Ledger\LedgerVerifier;
use App\Enums\TransactionType;
use App\Models\AiRequest;
use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Models\WhatsappMessage;
use App\Services\Privacy\PrivacyService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->user = ledgerUser('919876543210');
    $this->other = ledgerUser('919000000009');

    $rahul = app(CounterpartyService::class)->findOrCreate($this->user, 'Rahul');
    ledger()->post(command($this->user, TransactionType::Lend, '2000', ['counterpartyId' => $rahul->id, 'description' => 'Lent to Rahul for the wedding']));
    ledger()->post(command($this->user, TransactionType::Expense, '250', ['categoryId' => category($this->user, 'Vegetables')->id, 'description' => 'sabji from Sharma ji']));
    ledger()->post(command($this->other, TransactionType::Expense, '99', ['categoryId' => category($this->other, 'Fuel')->id, 'description' => 'other user note']));

    WhatsappMessage::create(['user_id' => $this->user->id, 'wa_message_id' => 'wamid.P1', 'peer_bidx' => $this->user->wa_id_bidx, 'direction' => 'in', 'message_type' => 'text', 'text' => 'gave Rahul 2000', 'status' => 'processed']);
    AiRequest::create(['user_id' => $this->user->id, 'provider' => 'fake', 'model' => 'm', 'request_type' => 'transaction_parser', 'attempt' => 1, 'status' => 'ok', 'input' => 'gave Rahul 2000', 'output' => '{"counterparty":"Rahul"}']);
    DB::table('webhook_events')->insert(['provider' => 'meta', 'event_hash' => str_repeat('c', 64), 'payload' => Crypt::encryptString('{"messages":[{"from":"919000000009"},{"from":"919876543210"}]}'), 'status' => 'processed', 'received_at' => now()]);
    DB::table('webhook_events')->insert(['provider' => 'meta', 'event_hash' => str_repeat('b', 64), 'payload' => Crypt::encryptString('{"entry":[{"changes":[{"value":{"messages":[{"from":"919876543210","text":{"body":"gave Rahul 2000"}}]}}]}]}'), 'status' => 'processed', 'received_at' => now()]);
    $this->balancesBefore = DB::table('ledger_entries')->where('user_id', $this->user->id)->selectRaw('account_id, SUM(CASE direction WHEN \'D\' THEN amount_minor ELSE -amount_minor END) n')->groupBy('account_id')->pluck('n', 'account_id')->all();
});

describe('export', function () {
    it('contains the user\'s own data only', function () {
        $data = app(PrivacyService::class)->export($this->user);

        $json = json_encode($data);
        expect($data['user']['whatsapp_number'])->toBe('919876543210')->and($data['transactions'])->toHaveCount(2)->and($data['people'][0]['name'])->toBe('Rahul')
            ->and($data['transactions'][0]['entries'])->not->toBeEmpty()->and($json)->not->toContain('other user note')->and($json)->not->toContain('919000000009');
    });

    it('is available as a command that writes a private file', function () {
        $path = tempnam(sys_get_temp_dir(), 'mt').'.json';
        $this->artisan('moneytalks:user:export', ['wa_id' => '919876543210', '--to' => $path])->assertSuccessful();

        expect(json_decode(file_get_contents($path), true)['format'])->toBe('moneytalks-export-v1')->and(substr(sprintf('%o', fileperms($path)), -4))->toBe('0600');
        @unlink($path);
    });
});

describe('erasure', function () {
    it('removes personal data everywhere it is stored but keeps the anonymous numbers and a valid ledger', function () {
        $this->artisan('moneytalks:user:erase', ['wa_id' => '919876543210', '--force' => true])->assertSuccessful();

        // Nothing identifying is left in any table that belongs to the user, and the raw webhook with the number is gone.
        $dump = collect(['users', 'whatsapp_messages', 'ai_requests', 'ledger_transactions', 'ledger_accounts', 'counterparties', 'merchants', 'user_aliases', 'webhook_events', 'audit_logs'])
            ->map(fn ($t) => json_encode(DB::table($t)->where(fn ($q) => $t === 'webhook_events' ? $q : (Schema::hasColumn($t, 'user_id') ? $q->where('user_id', $this->user->id) : (in_array($t, ['users']) ? $q->where('id', $this->user->id) : $q)))->get()))->implode('|');
        expect($dump)->not->toContain('Rahul')->and($dump)->not->toContain('Sharma')->and($dump)->not->toContain('wedding')->and($dump)->not->toContain('919876543210')
            ->and(WhatsappMessage::where('wa_message_id', 'wamid.P1')->first()->text)->toBeNull()
            ->and(AiRequest::where('user_id', $this->user->id)->first()->input)->toBeNull()
            ->and(DB::table('webhook_events')->where('event_hash', str_repeat('b', 64))->exists())->toBeFalse()   // only this person
            ->and(DB::table('webhook_events')->where('event_hash', str_repeat('c', 64))->exists())->toBeTrue();   // shared with another sender: kept (encrypted)

        // The books are untouched.
        $after = DB::table('ledger_entries')->where('user_id', $this->user->id)->selectRaw('account_id, SUM(CASE direction WHEN \'D\' THEN amount_minor ELSE -amount_minor END) n')->groupBy('account_id')->pluck('n', 'account_id')->all();
        expect($after)->toBe($this->balancesBefore)->and(app(LedgerVerifier::class)->verify())->toBe([])
            ->and(LedgerTransaction::where('user_id', $this->user->id)->whereNotNull('description')->count())->toBe(0)
            ->and(LedgerAccount::where('user_id', $this->user->id)->where('name', 'like', '%Person 1')->count())->toBe(1);
    });

    it('marks the user deleted, frees the number for a fresh sign-up, and leaves other users alone', function () {
        app(PrivacyService::class)->erase($this->user);

        expect(User::findByWaId('919876543210'))->toBeNull()->and(User::find($this->user->id)->status)->toBe('deleted')->and(User::find($this->user->id)->name)->toBeNull();

        $fresh = app(UserProvisioner::class)->provision('919876543210', 'New me');
        expect($fresh->id)->not->toBe($this->user->id)->and(LedgerTransaction::where('user_id', $fresh->id)->count())->toBe(0)
            ->and(LedgerTransaction::where('user_id', $this->other->id)->first()->description)->toBe('other user note')
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('records that an erasure happened, without recording what was erased', function () {
        app(PrivacyService::class)->erase($this->user);

        $log = DB::table('audit_logs')->where('action', 'user.erased')->first();
        expect($log)->not->toBeNull()->and($log->actor_type)->toBe('admin')->and(json_encode($log))->not->toContain('Rahul');
    });

    it('asks for confirmation unless forced, and refuses an unknown or already erased user', function () {
        $this->artisan('moneytalks:user:erase', ['wa_id' => '919876543210'])->expectsQuestion('Type ERASE to continue', 'no')->assertFailed();
        expect(User::find($this->user->id)->status)->not->toBe('deleted');

        $this->artisan('moneytalks:user:erase', ['wa_id' => '911111111111', '--force' => true])->assertFailed();
    });

    it('lets the database permit clearing a description but nothing else on a ledger row', function () {
        $tx = LedgerTransaction::where('user_id', $this->user->id)->first();

        DB::table('ledger_transactions')->where('id', $tx->id)->update(['description' => null]);
        expect(fn () => DB::table('ledger_transactions')->where('id', $tx->id)->update(['description' => 'rewritten']))->toThrow(QueryException::class)
            ->and(fn () => DB::table('ledger_transactions')->where('id', $tx->id)->update(['debit_total_minor' => 1]))->toThrow(QueryException::class);
    })->skip(fn () => ! dbHasLedgerTriggers(), 'needs the DB triggers');
});

describe('retention', function () {
    it('keeps raw webhook payloads encrypted at rest, clears them after 14 days, and clears old message bodies', function () {
        $event = WebhookEvent::create(['provider' => 'meta', 'event_hash' => str_repeat('d', 64), 'payload' => '{"text":"stranger says hello"}', 'status' => 'processed', 'received_at' => now()->subDays(20)]);
        $fresh = WebhookEvent::create(['provider' => 'meta', 'event_hash' => str_repeat('e', 64), 'payload' => '{"text":"fresh"}', 'status' => 'processed', 'received_at' => now()]);

        expect(DB::table('webhook_events')->where('id', $event->id)->value('payload'))->not->toContain('stranger');   // ciphertext in the table
        WhatsappMessage::create(['user_id' => $this->user->id, 'wa_message_id' => 'wamid.OLD', 'direction' => 'in', 'message_type' => 'text', 'text' => 'old body', 'status' => 'processed']);
        DB::table('whatsapp_messages')->where('wa_message_id', 'wamid.OLD')->update(['created_at' => now()->subDays(100)]);

        $this->artisan('moneytalks:retention:purge')->assertSuccessful();

        expect(WebhookEvent::find($event->id)->payload)->toBe('{}')->and(WebhookEvent::find($event->id)->payload_cleared_at)->not->toBeNull()
            ->and(WebhookEvent::find($fresh->id)->payload)->toBe('{"text":"fresh"}')
            ->and(WhatsappMessage::where('wa_message_id', 'wamid.OLD')->first()->text)->toBeNull()
            ->and(WhatsappMessage::where('wa_message_id', 'wamid.P1')->first()->text)->not->toBeNull();
    });

    it('sweeps leftover export files older than a day', function () {
        $dir = storage_path('app/private/exports');
        @mkdir($dir, 0700, true);
        file_put_contents($old = $dir.'/old-test.csv', 'x');
        file_put_contents($new = $dir.'/new-test.csv', 'x');
        touch($old, time() - 3 * 86400);

        $this->artisan('moneytalks:retention:purge')->assertSuccessful();

        expect(file_exists($old))->toBeFalse()->and(file_exists($new))->toBeTrue();
        @unlink($new);
    });
});
