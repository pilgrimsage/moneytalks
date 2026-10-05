<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CostReport extends Command
{
    protected $signature = 'moneytalks:cost:report {--days=30 : How many days back}';

    protected $description = 'What the app costs to run: AI by feature, WhatsApp messages by category, cost per transaction (docs/cost-model.md)';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $since = now()->subDays($days)->startOfDay();

        $this->info('AI by feature');
        $ai = DB::table('ai_requests')->where('created_at', '>=', $since)->selectRaw('request_type t, COUNT(*) n, SUM(status <> \'ok\') failed, SUM(estimated_cost_micros) c')->groupBy('t')->orderByDesc('c')->get();
        $this->table(['Feature', 'Requests', 'Failed', 'Cost (USD)'], $ai->map(fn ($r) => [$r->t, $r->n, (int) $r->failed, $this->usd((int) $r->c)])->all());
        $aiMicros = (int) $ai->sum('c');

        $this->newLine();
        $this->info('WhatsApp messages');
        $wa = DB::table('whatsapp_messages')->where('created_at', '>=', $since)->selectRaw("direction d, COALESCE(pricing_category, '-') cat, SUM(billable = 1) billable, COUNT(*) n")->groupBy('d', 'cat')->orderBy('d')->get();
        $this->table(['Direction', 'Pricing category', 'Messages', 'Billable'], $wa->map(fn ($r) => [$r->d, $r->cat, $r->n, (int) $r->billable])->all());

        $tx = DB::table('ledger_transactions')->where('created_at', '>=', $since)->where('type', '!=', 'reversal')->count();
        $users = DB::table('whatsapp_messages')->where('created_at', '>=', $since)->where('direction', 'in')->whereNotNull('user_id')->distinct()->count('user_id');

        $this->newLine();
        $this->line(sprintf('Last %d day(s): AI %s; %d transactions recorded; %d active user(s).', $days, $this->usd($aiMicros), $tx, $users));
        if ($tx > 0) {
            $this->line('AI cost per recorded transaction: '.$this->usd(intdiv($aiMicros, $tx)));
        }
        if ($users > 0) {
            $this->line('AI cost per active user: '.$this->usd(intdiv($aiMicros, $users)));
        }
        $this->line('WhatsApp charges follow Meta\'s own rate card (see the billable counts above); transcription is not priced here yet. Estimates only: check provider invoices.');

        return self::SUCCESS;
    }

    private function usd(int $micros): string
    {
        return '$'.number_format($micros / 1_000_000, 4);
    }
}
