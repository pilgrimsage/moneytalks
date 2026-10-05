<?php

namespace App\Services\Media;

use App\Models\User;
use App\Services\AI\AIGateway;
use App\Services\AI\Exceptions\AIUnavailableException;
use App\Services\Interpretation\Decision;
use App\Services\Interpretation\PromptContext;
use App\Services\Interpretation\ProposalValidator;
use App\Services\WhatsApp\DTO\MediaFile;
use Carbon\CarbonImmutable;

/**
 * A receipt photo becomes an ordinary expense PROPOSAL: the model reads the photo, the amount/date/category are
 * validated exactly like a typed message (category resolved against the user's own, date window, size limits), and
 * the handler then ALWAYS asks for a Confirm tap, because a photo can be misread. The image is never stored.
 */
class ReceiptService
{
    public function __construct(
        private readonly AIGateway $gateway,
        private readonly ProposalValidator $validator,
        private readonly PromptContext $context,
    ) {}

    /**
     * @return Decision|string a decision to act on, or a plain message for the user (not a receipt, unreadable, over the limit...)
     */
    public function read(User $user, MediaFile $file, string $caption, ?string $messageId, CarbonImmutable $now): Decision|string
    {
        if ($this->gateway->overDailyLimit($user)) {
            return 'You have reached today\'s limit for smart reading. Please type it instead, for example "spent 250 on groceries".';
        }

        try {
            ['response' => $response, 'request_id' => $requestId] = $this->gateway->readReceipt($user, '<user_message>'.$this->context->sanitize($caption).'</user_message>', $file->bytes, $file->mimeType, $messageId);
        } catch (AIUnavailableException) {
            return 'I couldn\'t read that photo right now. Please try again in a bit, or type it, for example "spent 250 on groceries".';
        }

        $d = $response->isOk() ? $response->data : null;
        if (! is_array($d) || ! array_key_exists('is_receipt', $d)) {
            $this->gateway->setOutcome($requestId, 'invalid_output');

            return 'I couldn\'t read that photo. Please try a clearer one, or type it, for example "spent 250 on groceries".';
        }
        if ($d['is_receipt'] !== true) {
            $this->gateway->setOutcome($requestId, 'unsupported');

            return 'That doesn\'t look like a receipt or bill. Send a photo of one, or just type the expense.';
        }
        $total = trim((string) ($d['total'] ?? ''));
        if ($total === '') {
            $this->gateway->setOutcome($requestId, 'clarify');

            return 'I could see a receipt but couldn\'t read the total. How much was it? For example "spent 250 on groceries".';
        }

        $iso = isset($d['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d['date']) ? (string) $d['date'] : null;
        $item = [
            'intent' => 'record_event', 'event_type' => 'expense', 'amount' => $total, 'currency' => $d['currency'] ?? null,
            'date' => $iso ? ['kind' => 'iso', 'iso' => $iso] : ['kind' => 'none'],
            'category' => $d['category'] ?? null, 'merchant' => $d['merchant'] ?? null, 'description' => $d['merchant'] ?? null,
            'confidence' => min(0.85, max(0.0, (float) ($d['confidence'] ?? 0))), // a photo never earns more than "probably right"
            'missing_fields' => [],
        ];

        // The receipt's own total is the "text" the amount is checked against, so the cross-check cannot fail spuriously.
        $decision = $this->validator->decideItem($user, $item, 0, $total.' '.$caption, $now);
        $this->gateway->setOutcome($requestId, $decision->kind === Decision::RECORD || $decision->kind === Decision::CONFIRM ? 'record' : 'clarify');

        return $decision;
    }
}
