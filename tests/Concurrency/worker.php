<?php

/**
 * Child process for ConcurrencyTest: boots the app and performs ONE ledger operation, printing
 * the outcome as JSON. Arguments: a single JSON string.
 */

use App\Domain\Ledger\DTO\PostingCommand;
use App\Domain\Ledger\LedgerService;
use App\Enums\TransactionType;
use App\Jobs\ProcessWebhookEvent;
use App\Support\Money;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$a = json_decode($argv[1], true);

// Barrier: every worker waits for the same instant so the operations really overlap.
$wait = $a['start_at'] - microtime(true);
if ($wait > 0) {
    usleep((int) ($wait * 1_000_000));
}

$ledger = $app->make(LedgerService::class);
$cmd = fn () => new PostingCommand(
    userId: $a['user'], type: TransactionType::Expense, money: Money::ofMinor($a['minor'], 'INR'),
    occurredOn: '2026-10-04', accountId: $a['account'], idempotencyKey: $a['key'], categoryId: $a['category'],
);

if ($a['op'] === 'event') {
    try {
        ProcessWebhookEvent::dispatchSync($a['event_id']);
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => get_class($e), 'message' => $e->getMessage()]);
    }

    return;
}

try {
    $r = match ($a['op']) {
        'post' => $ledger->post($cmd()),
        'reverse' => $ledger->reverse($a['user'], $a['target'], 'race'),
        'correct' => $ledger->correct($a['target'], $cmd(), 'race'),
    };
    echo json_encode(['ok' => true, 'id' => $r->transaction->id, 'replayed' => $r->replayed]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => get_class($e), 'message' => $e->getMessage()]);
}
