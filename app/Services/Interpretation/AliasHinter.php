<?php

namespace App\Services\Interpretation;

use App\Enums\EntityType;
use App\Models\Category;
use App\Models\LedgerAccount;
use App\Models\Merchant;
use App\Models\UserAlias;
use App\Support\Text;

/**
 * Picks the few of the user's own aliases that actually appear in the message, so the prompt carries
 * only relevant vocabulary ("sabji=Vegetables") instead of the whole alias table (token cost + privacy).
 */
class AliasHinter
{
    /** @return list<string> e.g. ["sabji=Vegetables", "hdfc=HDFC Bank"] */
    public function hints(string $userId, string $text, int $limit = 8): array
    {
        $words = explode(' ', Text::normalize($text));
        $grams = [];
        foreach (array_keys($words) as $i) {
            foreach ([1, 2, 3] as $n) {
                if ($i + $n <= count($words)) {
                    $grams[] = implode(' ', array_slice($words, $i, $n));
                }
            }
        }
        $grams = array_values(array_unique(array_filter($grams)));
        if ($grams === []) {
            return [];
        }

        $aliases = UserAlias::where('user_id', $userId)->whereIn('alias', $grams)->limit($limit)->get(['entity_type', 'entity_id', 'alias']);

        $names = [
            EntityType::Category->value => Category::whereIn('id', $aliases->where('entity_type', EntityType::Category)->pluck('entity_id'))->pluck('name', 'id'),
            EntityType::Merchant->value => Merchant::whereIn('id', $aliases->where('entity_type', EntityType::Merchant)->pluck('entity_id'))->pluck('name', 'id'),
            EntityType::Account->value => LedgerAccount::whereIn('id', $aliases->where('entity_type', EntityType::Account)->pluck('entity_id'))->pluck('name', 'id'),
        ];

        $out = [];
        foreach ($aliases as $a) {
            $name = $names[$a->entity_type->value][$a->entity_id] ?? null;
            if ($name !== null && mb_strtolower($name) !== $a->alias) {
                $out[] = "{$a->alias}={$name}";
            }
        }

        return array_values(array_unique($out));
    }
}
