<?php

namespace App\Support;

/**
 * Personal mode: only these ids may use the bot. Each channel has its own list, because a Telegram user id and a
 * WhatsApp number are different identities (and so different users with separate books).
 */
final class AllowList
{
    /** @return list<string> digits only, no empties */
    public static function parse(?string $list): array
    {
        return array_values(array_filter(array_map(
            fn (string $id) => preg_replace('/\D+/', '', $id) ?? '',
            explode(',', (string) $list)
        )));
    }

    /**
     * The list for the active channel. Telegram uses ALLOWED_TELEGRAM_IDS and falls back to ALLOWED_WA_IDS when it is
     * empty (so an existing single-list setup keeps working); every other channel uses ALLOWED_WA_IDS.
     *
     * @return list<string>
     */
    public static function forProvider(?string $provider, ?string $telegramList, ?string $whatsappList): array
    {
        if ($provider === 'telegram') {
            $own = self::parse($telegramList);

            return $own !== [] ? $own : self::parse($whatsappList);
        }

        return self::parse($whatsappList);
    }
}
