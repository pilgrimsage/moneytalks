<?php

namespace App\Console\Commands;

use App\Services\AI\Eval\EvalRunner;
use App\Services\AI\Prompts\TransactionParser;
use Illuminate\Console\Command;

class EvalAi extends Command
{
    protected $signature = 'moneytalks:ai:eval
        {--live : Ask the real model (costs money; needs ANTHROPIC_API_KEY and AI_PRIMARY_PROVIDER=anthropic)}
        {--model= : Model id to evaluate in live mode (default: AI_MODEL_FAST)}
        {--filter= : Only cases whose id contains this text}
        {--min-accuracy= : Gate: minimum share of cases that must pass (default 1.0 replay, 0.9 live)}
        {--yes : Do not ask before spending money in live mode}
        {--json : Print the report as JSON}';

    protected $description = 'Run the AI evaluation suite (docs/ai.md section 11). Exit code is non-zero if the gate fails.';

    public function handle(EvalRunner $runner): int
    {
        $path = base_path('tests/Evals/cases.php');
        if (! is_file($path)) {
            $this->error('Eval dataset not found (tests/Evals/cases.php). This command is for development and CI.');

            return self::FAILURE;
        }

        $cases = require $path;
        if ($filter = $this->option('filter')) {
            $cases = array_values(array_filter($cases, fn ($c) => str_contains($c['id'], $filter)));
        }
        if ($cases === []) {
            $this->error('No cases selected.');

            return self::FAILURE;
        }

        $live = (bool) $this->option('live');
        if ($live) { // cases that script a bad model answer only make sense when replaying
            $cases = array_values(array_filter($cases, fn ($c) => empty($c['replay_only'])));
            if ($cases === []) {
                $this->error('No live cases selected (the selection only has replay-only cases).');

                return self::FAILURE;
            }
        }
        $model = $this->option('model') ?: null;

        if ($live) {
            if (config('ai.provider') !== 'anthropic' || ! config('ai.anthropic.api_key')) {
                $this->error('Live mode needs AI_PRIMARY_PROVIDER=anthropic and ANTHROPIC_API_KEY.');

                return self::FAILURE;
            }
            $this->warn(sprintf('LIVE run: %d requests to %s using prompt %s. Rough cost: about $%.3f (estimate; ~1,700 input + 200 output tokens each at list price).',
                count($cases), $model ?? config('ai.models.fast'), TransactionParser::VERSION, $this->estimateUsd(count($cases))));
            if (! $this->option('yes') && ! $this->confirm('Spend this money?', false)) {
                return self::FAILURE;
            }
        }

        $report = $runner->run($cases, $live ? 'live' : 'replay', $model, fn (string $id, bool $ok) => $this->option('json') ? null : $this->output->write($ok ? '.' : 'F'));
        $min = (float) ($this->option('min-accuracy') ?? ($live ? 0.9 : 1.0));

        if ($this->option('json')) {
            $this->line(json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->newLine(2);
            $this->table(['Metric', 'Value'], [
                ['mode', $live ? 'LIVE ('.($model ?? config('ai.models.fast')).')' : 'replay of recorded answers'],
                ['prompt version', TransactionParser::VERSION],
                ['cases', $report->total],
                ['accuracy (all fields)', sprintf('%.1f%%  (%d/%d)', $report->accuracy() * 100, $report->passed, $report->total)],
                ['decision-kind accuracy', sprintf('%.1f%%', $report->kindAccuracy() * 100)],
                ['FALSE records (must be 0)', $report->falseRecords],
                ['WRONG records (must be 0)', $report->wrongRecords],
                ['missed records', $report->missedRecords],
                ['errors', $report->errors],
                ['latency p50 / p95', $report->percentile(50) === null ? 'n/a' : "{$report->percentile(50)} / {$report->percentile(95)} ms"],
                ['tokens in / out', $live ? "{$report->inputTokens} / {$report->outputTokens}" : 'n/a'],
                ['cost', $live ? sprintf('$%.4f (%.4f per case)', $report->costMicros / 1e6, $report->costMicros / 1e6 / max(1, $report->total)) : 'n/a'],
            ]);
            foreach ($report->failures as $f) {
                $this->error("✗ {$f['id']}: \"{$f['text']}\"");
                foreach ($f['problems'] as $p) {
                    $this->line("    {$p}");
                }
                $this->line("    got: {$f['got']}");
            }
        }

        $ok = $report->passes($min);
        $this->{$ok ? 'info' : 'error'}($ok ? "Eval gate passed (accuracy >= {$min}, no false/wrong records)." : 'Eval gate FAILED.');

        if ($live) {
            @mkdir(storage_path('app/evals'), 0775, true);
            file_put_contents(storage_path('app/evals/'.date('Ymd-His').'-'.TransactionParser::VERSION.'.json'), json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function estimateUsd(int $cases): float
    {
        // Haiku-class list price: $1 / MTok in, $5 / MTok out. A rough guide only; the real figure is reported after the run.
        return $cases * (1700 * 1 + 200 * 5) / 1_000_000;
    }
}
