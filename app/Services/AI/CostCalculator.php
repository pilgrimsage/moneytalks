<?php

namespace App\Services\AI;

use App\Models\AiModelPrice;
use App\Services\AI\DTO\StructuredResponse;
use Carbon\CarbonInterface;

/** Cost from the provider's own usage numbers and the price row effective at request time. */
class CostCalculator
{
    /** @return array{micros: int, currency: string, priced: bool} */
    public function estimate(string $provider, StructuredResponse $r, ?CarbonInterface $at = null): array
    {
        $price = AiModelPrice::where('provider', $provider)->where('model', $r->model)
            ->whereDate('effective_from', '<=', ($at ?? now())->toDateString())
            ->orderByDesc('effective_from')->first()
            // A served model id can carry a date suffix (e.g. claude-haiku-4-5-20251001): price it as its family.
            ?? AiModelPrice::where('provider', $provider)->whereRaw('? LIKE CONCAT(model, \'%\')', [$r->model])
                ->orderByDesc('effective_from')->first();

        if (! $price) {
            return ['micros' => 0, 'currency' => 'USD', 'priced' => false];
        }

        // tokens x (micro-dollars per million tokens) / 1,000,000, rounded up so tiny calls are never "free".
        $micros = 0;
        foreach ([
            [$r->inputTokens, $price->input_micros_per_mtok],
            [$r->outputTokens, $price->output_micros_per_mtok],
            [$r->cacheReadTokens, $price->cache_read_micros_per_mtok],
            [$r->cacheWriteTokens, $price->cache_write_micros_per_mtok],
        ] as [$tokens, $perMtok]) {
            $micros += intdiv($tokens * $perMtok + 999_999, 1_000_000);
        }

        return ['micros' => $micros, 'currency' => $price->currency, 'priced' => true];
    }
}
