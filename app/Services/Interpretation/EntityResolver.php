<?php

namespace App\Services\Interpretation;

use App\Enums\CategoryKind;
use App\Enums\EntityType;
use App\Models\Category;
use App\Models\Counterparty;
use App\Models\LedgerAccount;
use App\Models\Merchant;
use App\Models\UserAlias;
use App\Support\Text;
use Illuminate\Support\Collection;

/**
 * Maps a name the user typed to one of THEIR records: exact alias -> exact name -> fuzzy.
 * Every query is scoped by $userId, so one user's vocabulary can never resolve to another's data.
 * Hallucinated or unknown names come back as unresolved/ambiguous, never as a guess.
 */
class EntityResolver
{
    public function resolve(string $userId, EntityType $type, string $text, ?CategoryKind $kind = null): ResolutionResult
    {
        $needle = Text::normalize($text);
        if ($needle === '') {
            return ResolutionResult::unresolved($type, $text);
        }

        $names = $this->entityNames($userId, $type, $kind); // id => display name
        if ($names === []) {
            return ResolutionResult::unresolved($type, $text);
        }

        $aliases = UserAlias::where('user_id', $userId)->where('entity_type', $type->value)->get(['entity_id', 'alias']);

        // 1. exact alias
        $hit = $aliases->first(fn ($a) => $a->alias === $needle && isset($names[$a->entity_id]));
        if ($hit) {
            return ResolutionResult::resolved($type, $text, $hit->entity_id, $names[$hit->entity_id], 'alias', 1.0);
        }

        // 2. exact name (several records may share a leaf name, e.g. under different parents)
        $named = collect($names)->filter(fn ($n) => Text::normalize($n) === $needle);
        if ($named->count() === 1) {
            return ResolutionResult::resolved($type, $text, (string) $named->keys()->first(), (string) $named->first(), 'name', 1.0);
        }
        if ($named->count() > 1) {
            return ResolutionResult::ambiguous($type, $text, $named->map(
                fn ($n, $id) => ['id' => (string) $id, 'name' => $n, 'score' => 1.0]
            )->values()->all());
        }

        // 3. fuzzy, over names and aliases
        return $this->fuzzy($type, $text, $needle, $names, $aliases);
    }

    /**
     * @param  array<string, string>  $names
     * @param  Collection<int, UserAlias>  $aliases
     */
    private function fuzzy(EntityType $type, string $text, string $needle, array $names, Collection $aliases): ResolutionResult
    {
        $cfg = config('moneytalks.resolver');
        if (mb_strlen($needle, 'UTF-8') < $cfg['fuzzy_min_length']) {
            return ResolutionResult::unresolved($type, $text);
        }

        $best = []; // entity id => best similarity
        $consider = function (string $id, string $candidate) use ($needle, &$best) {
            $score = Text::similarity($needle, Text::normalize($candidate));
            if ($score > ($best[$id] ?? 0.0)) {
                $best[$id] = $score;
            }
        };

        foreach ($names as $id => $name) {
            $consider((string) $id, $name);
        }
        foreach ($aliases as $alias) {
            if (isset($names[$alias->entity_id])) {
                $consider($alias->entity_id, $alias->alias);
            }
        }

        $ranked = collect($best)->filter(fn ($s) => $s >= $cfg['fuzzy_min_similarity'])->sortDesc();
        if ($ranked->isEmpty()) {
            return ResolutionResult::unresolved($type, $text);
        }

        $top = $ranked->slice(0, 3)->map(fn ($score, $id) => [
            'id' => (string) $id, 'name' => $names[$id], 'score' => round($score, 4),
        ])->values()->all();

        if (count($top) > 1 && ($top[0]['score'] - $top[1]['score']) < $cfg['ambiguity_margin']) {
            return ResolutionResult::ambiguous($type, $text, $top);
        }

        // A wrong person on a debt is worse than one extra question: never auto-pick a person
        // from a fuzzy match ("Rahim" is not "Rahil"). The caller asks "Did you mean ...?".
        if ($type === EntityType::Counterparty) {
            return ResolutionResult::ambiguous($type, $text, $top);
        }

        return ResolutionResult::resolved($type, $text, $top[0]['id'], $top[0]['name'], 'fuzzy', $top[0]['score']);
    }

    /** @return array<string, string> id => display name (the user's own records only) */
    private function entityNames(string $userId, EntityType $type, ?CategoryKind $kind): array
    {
        return match ($type) {
            EntityType::Category => Category::where('user_id', $userId)->where('is_active', true)
                ->when($kind, fn ($q) => $q->where('kind', $kind->value))
                ->pluck('name', 'id')->all(),
            EntityType::Merchant => Merchant::where('user_id', $userId)->pluck('name', 'id')->all(),
            EntityType::Counterparty => Counterparty::where('user_id', $userId)->where('status', 'active')->pluck('name', 'id')->all(),
            // Only accounts the user names in conversation (not system or per-person accounts).
            EntityType::Account => LedgerAccount::where('user_id', $userId)->where('status', 'active')
                ->whereNotIn('subtype', ['system', 'receivable', 'payable'])->pluck('name', 'id')->all(),
        };
    }
}
