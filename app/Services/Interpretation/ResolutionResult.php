<?php

namespace App\Services\Interpretation;

use App\Enums\EntityType;

/**
 * Outcome of resolving a user-typed name ("sabji") to one of the user's own records.
 * The AI never supplies IDs; this is the only way a name becomes an entity reference.
 */
final class ResolutionResult
{
    /**
     * @param  list<array{id: string, name: string, score: float}>  $candidates  for Ambiguous
     */
    private function __construct(
        public readonly string $status, // resolved | ambiguous | unresolved
        public readonly EntityType $type,
        public readonly string $input,
        public readonly ?string $entityId = null,
        public readonly ?string $name = null,
        public readonly ?string $matchType = null, // alias | name | fuzzy
        public readonly float $score = 0.0,
        public readonly array $candidates = [],
    ) {}

    public static function resolved(EntityType $type, string $input, string $id, string $name, string $matchType, float $score): self
    {
        return new self('resolved', $type, $input, $id, $name, $matchType, $score);
    }

    /** @param list<array{id: string, name: string, score: float}> $candidates */
    public static function ambiguous(EntityType $type, string $input, array $candidates): self
    {
        return new self('ambiguous', $type, $input, candidates: $candidates);
    }

    public static function unresolved(EntityType $type, string $input): self
    {
        return new self('unresolved', $type, $input);
    }

    public function isResolved(): bool
    {
        return $this->status === 'resolved';
    }

    public function isAmbiguous(): bool
    {
        return $this->status === 'ambiguous';
    }

    public function isUnresolved(): bool
    {
        return $this->status === 'unresolved';
    }
}
