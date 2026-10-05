<?php

namespace App\Enums;

enum MessageStatus: string
{
    // inbound
    case Received = 'received';
    case Processing = 'processing';
    case Processed = 'processed';
    case ProcessingFailed = 'processing_failed';
    case Ignored = 'ignored';
    // outbound
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';

    /** Delivery receipts only move forward: sent < delivered < read. */
    public function deliveryRank(): int
    {
        return match ($this) {
            self::Queued => 0, self::Sent => 1, self::Delivered => 2, self::Read => 3,
            default => -1,
        };
    }
}
