<?php

use App\Enums\MessageStatus;
use App\Models\WhatsappMessage;
use App\Services\WhatsApp\DTO\InboundStatus;
use App\Services\WhatsApp\DTO\Outbound;
use App\Services\WhatsApp\Exceptions\PermanentSendException;
use App\Services\WhatsApp\Exceptions\TransientSendException;
use App\Services\WhatsApp\OutboundMessageService;
use App\Services\WhatsApp\StatusUpdater;
use App\Services\WhatsApp\Testing\FakeWhatsAppProvider;

beforeEach(function () {
    FakeWhatsAppProvider::reset();
    config(['whatsapp.outbound.retry_backoff_ms' => [0, 0]]);
    $this->user = ledgerUser('919876543210');
    $this->user->update(['last_inbound_at' => now()->subHour()]);   // inside the 24h window
    $this->out = app(OutboundMessageService::class);
});

describe('the 24-hour customer-service window', function () {
    it('sends free-form text inside the window', function () {
        $row = $this->out->sendText($this->user->fresh(), 'hello', 'k1');

        expect($row->status)->toBe(MessageStatus::Sent)->and(sentTexts())->toBe(['hello']);
    });

    it('refuses free-form text outside the window, recording why instead of dropping it', function () {
        $this->user->update(['last_inbound_at' => now()->subHours(25)]);

        $row = $this->out->sendText($this->user->fresh(), 'your EMI is due', 'k2');

        expect($row->status)->toBe(MessageStatus::Failed)
            ->and($row->error)->toBe('outside_window_no_template')
            ->and(FakeWhatsAppProvider::$sent)->toBe([]);
    });

    it('refuses free-form text when the user has never messaged us', function () {
        $this->user->update(['last_inbound_at' => null]);

        expect($this->out->sendText($this->user->fresh(), 'hi', 'k3')->error)->toBe('outside_window_no_template');
    });

    it('still allows template messages outside the window', function () {
        $this->user->update(['last_inbound_at' => now()->subDays(3)]);

        $row = $this->out->sendTemplate($this->user->fresh(), 'emi_due', 'k4', 'en', [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => '4200']]]]);

        expect($row->status)->toBe(MessageStatus::Sent)
            ->and(FakeWhatsAppProvider::$sent[0]->kind)->toBe('template')
            ->and(FakeWhatsAppProvider::$sent[0]->templateName)->toBe('emi_due');
    });

    it('treats the boundary precisely', function () {
        $this->user->update(['last_inbound_at' => now()->subHours(23)->subMinutes(59)]);
        expect($this->out->isWithinWindow($this->user->fresh()))->toBeTrue();

        $this->user->update(['last_inbound_at' => now()->subHours(24)->subMinute()]);
        expect($this->out->isWithinWindow($this->user->fresh()))->toBeFalse();
    });
});

describe('retries and failures', function () {
    it('retries transient errors and then succeeds', function () {
        FakeWhatsAppProvider::$failures = [new TransientSendException('429'), new TransientSendException('503')];

        $row = $this->out->sendText($this->user->fresh(), 'hello', 'k5');

        expect($row->status)->toBe(MessageStatus::Sent)->and(FakeWhatsAppProvider::$sent)->toHaveCount(1);
    });

    it('gives up after the configured attempts and records the failure', function () {
        FakeWhatsAppProvider::$failures = array_fill(0, 3, new TransientSendException('timeout'));

        $row = $this->out->sendText($this->user->fresh(), 'hello', 'k6');

        expect($row->status)->toBe(MessageStatus::Failed)->and($row->error)->toStartWith('transient:');
    });

    it('does not retry permanent errors', function () {
        FakeWhatsAppProvider::$failures = [new PermanentSendException('bad recipient', '131026'), new TransientSendException('unused')];

        $row = $this->out->sendText($this->user->fresh(), 'hello', 'k7');

        expect($row->status)->toBe(MessageStatus::Failed)
            ->and($row->error)->toStartWith('permanent:')
            ->and(FakeWhatsAppProvider::$failures)->toHaveCount(1);   // the second was never consumed
    });

    it('never sends the same reply twice, even if the job reruns', function () {
        $this->out->sendText($this->user->fresh(), 'hello', 'reply:abc:0');
        $this->out->sendText($this->user->fresh(), 'hello', 'reply:abc:0');
        $this->out->sendText($this->user->fresh(), 'hello (changed)', 'reply:abc:0');

        expect(FakeWhatsAppProvider::$sent)->toHaveCount(1)
            ->and(WhatsappMessage::where('direction', 'out')->count())->toBe(1);
    });

    it('can resend a failed message under the same key', function () {
        FakeWhatsAppProvider::$failures = [new PermanentSendException('nope')];
        $this->out->sendText($this->user->fresh(), 'hello', 'k8');
        $row = $this->out->sendText($this->user->fresh(), 'hello', 'k8');

        expect($row->status)->toBe(MessageStatus::Sent)->and(WhatsappMessage::count())->toBe(1);
    });
});

describe('formatting limits', function () {
    it('splits very long text into several messages at natural boundaries', function () {
        config(['whatsapp.outbound.max_text_length' => 100]);
        $text = collect(range(1, 12))->map(fn ($i) => "Line number {$i} of the long report")->implode("\n");

        $this->out->sendText($this->user->fresh(), $text, 'long');

        $parts = sentTexts();
        expect(count($parts))->toBeGreaterThan(1)
            ->and(collect($parts)->every(fn ($p) => mb_strlen($p) <= 100))->toBeTrue()
            ->and(preg_replace('/\s+/', ' ', implode(' ', $parts)))->toBe(preg_replace('/\s+/', ' ', $text));
    });

    it('enforces WhatsApp button limits', function () {
        $ok = fn (int $n, string $title = 'Yes') => Outbound::buttons('91', 'Q?', array_map(fn ($i) => ['id' => "b{$i}", 'title' => $title], $n === 0 ? [] : range(1, $n)));

        expect($ok(3)->buttons)->toHaveCount(3)
            ->and(fn () => $ok(4))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $ok(0))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $ok(1, str_repeat('x', 21)))->toThrow(InvalidArgumentException::class)
            ->and(fn () => Outbound::text('91', '  '))->toThrow(InvalidArgumentException::class);
    });

    it('sends reply buttons with ids the processor can route on', function () {
        $this->out->sendButtons($this->user->fresh(), 'Record ₹500 for groceries?', [['id' => 'confirm:p1', 'title' => 'Confirm'], ['id' => 'cancel:p1', 'title' => 'Cancel']], 'btn1');

        expect(FakeWhatsAppProvider::$sent[0]->kind)->toBe('buttons')
            ->and(FakeWhatsAppProvider::$sent[0]->buttons[0]['id'])->toBe('confirm:p1');
    });
});

describe('delivery receipts', function () {
    beforeEach(function () {
        $this->row = $this->out->sendText($this->user->fresh(), 'hello', 'rcpt');
        $this->status = fn (string $s, array $extra = []) => app(StatusUpdater::class)->apply(
            new InboundStatus($this->row->wa_message_id, $s, time(), ...$extra)
        );
    });

    it('moves forward through delivered and read', function () {
        ($this->status)('delivered');
        expect($this->row->fresh()->status)->toBe(MessageStatus::Delivered);

        ($this->status)('read');
        expect($this->row->fresh()->status)->toBe(MessageStatus::Read);
    });

    it('never moves backwards when receipts arrive out of order', function () {
        ($this->status)('read');
        ($this->status)('delivered');
        ($this->status)('sent');

        expect($this->row->fresh()->status)->toBe(MessageStatus::Read);
    });

    it('records Meta billing information for cost tracking', function () {
        ($this->status)('sent', ['pricingCategory' => 'service', 'pricingModel' => 'CBP', 'billable' => false]);

        $fresh = $this->row->fresh();
        expect($fresh->pricing_category)->toBe('service')
            ->and($fresh->pricing_model)->toBe('CBP')
            ->and($fresh->billable)->toBeFalse();
    });

    it('records a delivery failure with Meta\'s reason', function () {
        ($this->status)('failed', ['errorCode' => '131047', 'errorMessage' => 'Re-engagement message']);

        $fresh = $this->row->fresh();
        expect($fresh->status)->toBe(MessageStatus::Failed)->and($fresh->error)->toContain('131047');
    });

    it('ignores receipts for messages it does not know', function () {
        expect(app(StatusUpdater::class)->apply(new InboundStatus('wamid.UNKNOWN', 'read', time())))->toBeFalse();
    });

    it('applies receipts that arrive through the webhook', function () {
        postWebhook($this, waFactory()->status('919876543210', $this->row->wa_message_id, 'delivered', [
            'pricing' => ['billable' => true, 'pricing_model' => 'PMP', 'category' => 'utility'],
        ]))->assertOk();

        $fresh = $this->row->fresh();
        expect($fresh->status)->toBe(MessageStatus::Delivered)
            ->and($fresh->pricing_category)->toBe('utility')
            ->and($fresh->billable)->toBeTrue();
    });
});
