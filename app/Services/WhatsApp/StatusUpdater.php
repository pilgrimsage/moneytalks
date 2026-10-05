<?php

namespace App\Services\WhatsApp;

use App\Enums\MessageStatus;
use App\Models\WhatsappMessage;
use App\Services\WhatsApp\DTO\InboundStatus;
use Illuminate\Support\Str;

/** Applies delivery receipts (sent/delivered/read/failed + pricing) to the messages we sent. */
class StatusUpdater
{
    public function apply(InboundStatus $s): bool
    {
        $row = WhatsappMessage::where('wa_message_id', $s->waMessageId)->where('direction', 'out')->first();
        if (! $row) {
            return false; // not a message we know (or it arrived before we stored its id)
        }

        $updates = [];

        if ($s->status === 'failed') {
            $updates['status'] = MessageStatus::Failed;
            $updates['error'] = Str::limit('meta '.($s->errorCode ?? '?').': '.($s->errorMessage ?? 'delivery failed'), 250, '');
        } elseif (($new = MessageStatus::tryFrom($s->status)) && $new->deliveryRank() > $row->status->deliveryRank()) {
            // Receipts can arrive out of order; only ever move forward (sent < delivered < read).
            $updates['status'] = $new;
        }

        if ($s->pricingCategory !== null) {
            $updates['pricing_category'] = $s->pricingCategory;
        }
        if ($s->pricingModel !== null) {
            $updates['pricing_model'] = $s->pricingModel;
        }
        if ($s->billable !== null) {
            $updates['billable'] = $s->billable;
        }

        if ($updates !== []) {
            $row->update($updates);
        }

        return true;
    }
}
