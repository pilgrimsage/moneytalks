<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AiUsage extends Command
{
    protected $signature = 'moneytalks:ai:usage {--days=7 : How many days back}';

    protected $description = 'AI usage and estimated cost by day, model and outcome (from ai_requests)';

    public function handle(): int
    {
        $since = now()->subDays((int) $this->option('days'))->startOfDay();

        $base = fn () => DB::table('ai_requests')->where('created_at', '>=', $since);

        $this->info('By day and model');
        $this->table(['Day', 'Model', 'Requests', 'Failed', 'Tokens in', 'Tokens out', 'Cost (USD)'],
            $base()->selectRaw("DATE(created_at) d, model, COUNT(*) n, SUM(status <> 'ok') failed, SUM(input_tokens) tin, SUM(output_tokens) tout, SUM(estimated_cost_micros) c")
                ->groupBy('d', 'model')->orderBy('d')->orderBy('model')->get()
                ->map(fn ($r) => [$r->d, $r->model, $r->n, (int) $r->failed, (int) $r->tin, (int) $r->tout, $this->usd((int) $r->c)])->all());

        $totals = $base()->selectRaw('COUNT(*) n, SUM(estimated_cost_micros) c, COUNT(DISTINCT user_id) users')->first();
        $recorded = $base()->where('outcome', 'record')->count();
        $cost = (int) $totals->c;

        $this->newLine();
        $this->info('By outcome');
        $this->table(['Outcome', 'Requests', 'Cost (USD)'],
            $base()->selectRaw('COALESCE(outcome, status) o, COUNT(*) n, SUM(estimated_cost_micros) c')->groupBy('o')->orderByDesc('n')->get()
                ->map(fn ($r) => [$r->o, $r->n, $this->usd((int) $r->c)])->all());

        $this->newLine();
        $this->line(sprintf('Total: %d requests, %s over %d day(s)%s.', $totals->n, $this->usd($cost), (int) $this->option('days'),
            $recorded > 0 ? sprintf('; %s per recorded transaction', $this->usd(intdiv($cost, $recorded))) : ''));
        $this->line('Costs are estimates from provider-reported token usage and the ai_model_prices table; verify against the provider invoice.');

        return self::SUCCESS;
    }

    private function usd(int $micros): string
    {
        return '$'.number_format($micros / 1_000_000, 4);
    }
}
