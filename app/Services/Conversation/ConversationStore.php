<?php

namespace App\Services\Conversation;

use App\Models\ConversationState;
use App\Models\User;

/**
 * Short-lived per-user memory: the one thing we are waiting on (an answer, or a Confirm/Cancel).
 * Not a chat history; nothing here is ever sent to the AI.
 */
class ConversationStore
{
    public const CLARIFY = 'clarify';

    public const CONFIRM = 'confirm';

    /** The live pending item, or null. Expired items are deleted on sight. */
    public function current(User $user): ?ConversationState
    {
        $state = ConversationState::where('user_id', $user->id)->first();
        if ($state && $state->isExpired()) {
            $state->delete();

            return null;
        }

        return $state;
    }

    /** Find by id for a button tap; must belong to the user and still be live. */
    public function find(User $user, string $id): ?ConversationState
    {
        $state = $this->current($user);

        return $state && $state->id === $id ? $state : null;
    }

    /**
     * Replace the user's pending item. A retry of the SAME inbound message keeps the existing item (and its id),
     * so buttons already sent to the user keep working.
     */
    public function put(User $user, string $kind, array $payload, string $sourceWaMessageId, int $turns = 0): ConversationState
    {
        $existing = $this->current($user);
        if ($existing && $existing->source_wa_message_id === $sourceWaMessageId && $existing->kind === $kind) {
            return $existing;
        }

        ConversationState::where('user_id', $user->id)->delete();

        return ConversationState::create([
            'user_id' => $user->id, 'kind' => $kind, 'payload' => $payload,
            'source_wa_message_id' => $sourceWaMessageId, 'turns' => $turns,
            'expires_at' => now()->addMinutes((int) config('moneytalks.conversation.ttl_minutes')),
        ]);
    }

    public function clear(User $user): void
    {
        ConversationState::where('user_id', $user->id)->delete();
    }

    public function purgeExpired(): int
    {
        return ConversationState::where('expires_at', '<', now())->delete();
    }
}
