<?php

use App\Domain\Finance\UserProvisioner;
use App\Domain\Ledger\AccountService;
use App\Domain\Ledger\DTO\PostingCommand;
use App\Domain\Ledger\LedgerService;
use App\Enums\TransactionType;
use App\Models\Category;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\WhatsApp\Handlers\InterpretationHandler;
use App\Services\WhatsApp\Testing\FakeWhatsAppProvider;
use App\Services\WhatsApp\Testing\MetaPayloadFactory;
use App\Support\Money;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
| Feature tests extend the Laravel TestCase and run against real MySQL/MariaDB.
| Unit tests are pure PHP with no framework or database.
*/
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Concurrency tests spawn real processes, so data must be really committed (no wrapping
// transaction); tables are truncated between tests instead.
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->in('Concurrency');

/* ---- ledger test helpers ---- */

function ledgerUser(string $waId = '919876543210'): User
{
    return app(UserProvisioner::class)->provision($waId);
}

function account(User $user, string $name): LedgerAccount
{
    return LedgerAccount::where('user_id', $user->id)->where('name', $name)->firstOrFail();
}

function category(User $user, string $name): Category
{
    return Category::where('user_id', $user->id)->where('name', $name)->firstOrFail();
}

function rupees(string $amount): Money
{
    return Money::parse($amount, 'INR');
}

/** Build a command with sensible defaults; override any constructor argument by name. */
function command(User $user, TransactionType $type, string $amount, array $overrides = []): PostingCommand
{
    static $n = 0;
    $defaults = [
        'userId' => $user->id,
        'type' => $type,
        'money' => rupees($amount),
        'occurredOn' => '2026-10-04',
        'accountId' => account($user, 'Cash')->id,
        'idempotencyKey' => 'test:'.(++$n).':'.bin2hex(random_bytes(4)),
    ];
    $args = $overrides + $defaults; // overrides win

    return new PostingCommand(...$args);
}

function ledger(): LedgerService
{
    return app(LedgerService::class);
}

function balanceOf(LedgerAccount $account): int
{
    return app(AccountService::class)->balance($account)->minor;
}

function dbHasLedgerTriggers(): bool
{
    return DB::table('information_schema.TRIGGERS')
        ->where('TRIGGER_SCHEMA', DB::getDatabaseName())
        ->where('TRIGGER_NAME', 'ledger_entries_no_update')->exists();
}

/* ---- WhatsApp test helpers ---- */

function waFactory(): MetaPayloadFactory
{
    return MetaPayloadFactory::fromConfig();
}

/** POST a correctly signed webhook (raw body + HMAC header), like Meta does. */
function postWebhook(TestCase $t, array $payload, ?string $signature = null, ?string $rawOverride = null)
{
    $signed = waFactory()->sign($payload);
    $body = $rawOverride ?? $signed['body'];

    return $t->call('POST', '/webhooks/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $signature ?? $signed['signature'],
    ], $body);
}

function sentTexts(): array
{
    return array_map(fn ($o) => $o->body, FakeWhatsAppProvider::$sent);
}

/* ---- AI test helpers ---- */

/** One item of the model's JSON output, with every field present (as structured outputs guarantee). */
function aiItem(array $o = []): array
{
    return $o + [
        'intent' => 'record_event', 'event_type' => 'expense', 'amount' => '250', 'currency' => null,
        'date' => ['kind' => 'none', 'offset_days' => null, 'weekday' => null, 'which' => null, 'day' => null, 'month' => null, 'year' => null, 'iso' => null],
        'category' => 'vegetables', 'merchant' => null, 'account' => null, 'to_account' => null, 'counterparty' => null, 'participants' => null, 'due_date' => null, 'interest_rate' => null, 'tenure_months' => null, 'emi_amount' => null, 'action' => null, 'recurrence' => null,
        'payment_method' => null, 'description' => null, 'target_kind' => null, 'target_amount' => null, 'target_text' => null,
        'query_metric' => null, 'period' => null, 'compare_period' => null, 'group_by' => null, 'limit' => null, 'search_text' => null, 'export_format' => null,
        'missing_fields' => [], 'clarification_question' => null, 'confidence' => 0.97,
    ];
}

function aiDate(string $kind, array $o = []): array
{
    return ['kind' => $kind] + $o + ['offset_days' => null, 'weekday' => null, 'which' => null, 'day' => null, 'month' => null, 'year' => null, 'iso' => null];
}

function aiEnvelope(array ...$items): array
{
    return ['language' => 'en', 'items' => array_values($items)];
}

function useInterpretationHandler(): void
{
    config(['whatsapp.handler' => InterpretationHandler::class]);
}

/* ---- conversation test helpers ---- */

function waText(TestCase $t, string $text, ?string $id = null, string $from = '919876543210'): void
{
    postWebhook($t, waFactory()->text($from, $text, $id))->assertOk();
}

function waTap(TestCase $t, string $replyId, string $title = 'Confirm', string $from = '919876543210'): void
{
    postWebhook($t, waFactory()->buttonReply($from, $replyId, $title))->assertOk();
}

/** The buttons of the most recent outbound interactive message, e.g. ['confirm' => 'confirm:01H..', 'cancel' => 'cancel:01H..']. */
function lastButtons(): array
{
    $sent = collect(FakeWhatsAppProvider::$sent)->filter(fn ($o) => $o->kind === 'buttons')->last();

    return $sent ? collect($sent->buttons)->mapWithKeys(fn ($b) => [explode(':', $b['id'])[0] => $b['id']])->all() : [];
}

function lastButtonTitles(): array
{
    $sent = collect(FakeWhatsAppProvider::$sent)->filter(fn ($o) => $o->kind === 'buttons')->last();

    return $sent ? array_column($sent->buttons, 'title') : [];
}
