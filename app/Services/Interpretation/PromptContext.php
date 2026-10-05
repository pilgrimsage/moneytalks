<?php

namespace App\Services\Interpretation;

use App\Models\LedgerAccount;
use App\Models\User;
use Carbon\CarbonImmutable;

/** Builds the per-request user content: a small <context> block and the untrusted <user_message>. */
class PromptContext
{
    public const MAX_MESSAGE_CHARS = 1500;

    public function __construct(private readonly AliasHinter $hinter) {}

    public function build(User $user, string $text, CarbonImmutable $now): string
    {
        $local = $now->setTimezone($user->timezone);
        $accounts = LedgerAccount::where('user_id', $user->id)->where('status', 'active')
            ->whereNotIn('subtype', ['system', 'receivable', 'payable'])->orderBy('name')->pluck('name')->all();
        $defaultId = $user->settings?->default_account_id;
        $default = $defaultId ? LedgerAccount::whereKey($defaultId)->value('name') : null;
        $hints = $this->hinter->hints($user->id, $text);

        $lines = [
            'today: '.$local->format('Y-m-d').' ('.$local->format('l').'), timezone '.$user->timezone,
            'accounts: '.($accounts ? implode(', ', $accounts) : '(none)'),
            'default_account: '.($default ?? '(none)'),
        ];
        if ($hints) {
            $lines[] = 'alias_hints: '.implode('; ', $hints);
        }

        return "<context>\n".implode("\n", $lines)."\n</context>\n<user_message>\n".$this->sanitize($text)."\n</user_message>";
    }

    /** The user's text can never close or fake our delimiters. */
    public function sanitize(string $text): string
    {
        $text = mb_substr($text, 0, self::MAX_MESSAGE_CHARS, 'UTF-8');
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text) ?? $text;

        return str_replace(['<', '>'], ['＜', '＞'], $text);
    }
}
