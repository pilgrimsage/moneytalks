<?php

namespace App\Services\AI\Eval;

/** Outcome of one eval run. The numbers that matter most are the false/wrong records: nothing wrong may be recorded. */
final class EvalReport
{
    public int $total = 0;

    public int $passed = 0;

    public int $kindCorrect = 0;

    /** Recorded something the dataset says must NOT be recorded. The worst kind of error. */
    public int $falseRecords = 0;

    /** Recorded, but with a wrong amount/category/account/date. Also unacceptable. */
    public int $wrongRecords = 0;

    /** Should have recorded, but asked or declined instead (annoying, not harmful). */
    public int $missedRecords = 0;

    public int $errors = 0;

    public int $inputTokens = 0;

    public int $outputTokens = 0;

    public int $costMicros = 0;

    /** @var list<int> */
    public array $latenciesMs = [];

    /** @var list<array{id: string, text: string, problems: list<string>, got: string}> */
    public array $failures = [];

    public function accuracy(): float
    {
        return $this->total === 0 ? 0.0 : $this->passed / $this->total;
    }

    public function kindAccuracy(): float
    {
        return $this->total === 0 ? 0.0 : $this->kindCorrect / $this->total;
    }

    public function percentile(int $p): ?int
    {
        if ($this->latenciesMs === []) {
            return null;
        }
        $sorted = $this->latenciesMs;
        sort($sorted);

        return $sorted[(int) max(0, ceil($p / 100 * count($sorted)) - 1)];
    }

    /** The gate: no false or wrong records, and accuracy at or above the minimum. */
    public function passes(float $minAccuracy): bool
    {
        return $this->falseRecords === 0 && $this->wrongRecords === 0 && $this->errors === 0 && $this->accuracy() >= $minAccuracy;
    }

    public function toArray(): array
    {
        return [
            'total' => $this->total, 'passed' => $this->passed,
            'accuracy' => round($this->accuracy(), 4), 'kind_accuracy' => round($this->kindAccuracy(), 4),
            'false_records' => $this->falseRecords, 'wrong_records' => $this->wrongRecords, 'missed_records' => $this->missedRecords,
            'errors' => $this->errors,
            'input_tokens' => $this->inputTokens, 'output_tokens' => $this->outputTokens, 'cost_usd' => round($this->costMicros / 1_000_000, 6),
            'latency_p50_ms' => $this->percentile(50), 'latency_p95_ms' => $this->percentile(95),
            'failures' => $this->failures,
        ];
    }
}
