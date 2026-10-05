<?php

use App\Enums\TransactionType;
use App\Models\WhatsappMessage;
use App\Services\Closing\MonthlyClosing;
use App\Services\WhatsApp\Testing\FakeWhatsAppProvider;
use Carbon\CarbonImmutable;

beforeEach(function () {
    FakeWhatsAppProvider::reset();
    config(['whatsapp.outbound.retry_backoff_ms' => [0, 0]]);
    $this->user = ledgerUser('919876543210');
    $this->user->forceFill(['last_inbound_at' => now()])->save();
    ledger()->post(command($this->user, TransactionType::Income, '45000', ['occurredOn' => '2026-09-01', 'categoryId' => category($this->user, 'Salary')->id]));
    ledger()->post(command($this->user, TransactionType::Expense, '9000', ['occurredOn' => '2026-09-10', 'categoryId' => category($this->user, 'Vegetables')->id]));
    $this->at = fn (string $when) => CarbonImmutable::parse($when, 'Asia/Kolkata')->utc();
});

it('sends last month\'s summary once, in the first week, from the morning', function () {
    $closing = app(MonthlyClosing::class);

    expect($closing->run(($this->at)('2026-10-01 08:00')))->toBe(0)   // too early in the day
        ->and($closing->run(($this->at)('2026-10-04 10:00')))->toBe(1)
        ->and($closing->run(($this->at)('2026-10-05 10:00')))->toBe(0); // already sent

    $body = sentTexts()[0];
    expect($body)->toContain('Your September wrap-up')->and($body)->toContain('Income: ₹45,000')->and($body)->toContain('Expenses: ₹9,000')->and(count(sentTexts()))->toBe(1);
});

it('stays quiet after the first week and for months without activity', function () {
    $closing = app(MonthlyClosing::class);

    expect($closing->run(($this->at)('2026-10-15 10:00')))->toBe(0)->and($closing->run(($this->at)('2027-03-02 10:00')))->toBe(0)->and(sentTexts())->toBe([]);
});

it('records a failure when the window is closed instead of dropping it, and sends later when it opens', function () {
    $this->user->forceFill(['last_inbound_at' => now()->subDays(3)])->save();
    $closing = app(MonthlyClosing::class);

    expect($closing->run(($this->at)('2026-10-02 10:00')))->toBe(0)->and(WhatsappMessage::where('status', 'failed')->count())->toBe(1);

    $this->user->forceFill(['last_inbound_at' => now()])->save();
    expect($closing->run(($this->at)('2026-10-03 10:00')))->toBe(1)->and(WhatsappMessage::where('dedupe_key', 'like', 'monthly:%')->count())->toBe(1);
});
