<?php

namespace App\Services\Reporting;

use App\Models\Category;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Merchant;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * CSV export of a user's own posted transactions. Plain columns, UTF-8 with a BOM (so Excel reads rupee signs and
 * Hindi correctly), amounts as decimals, and spreadsheet-formula hardening for text cells.
 */
class ExportService
{
    public const MAX_ROWS = 20000;

    public const COLUMNS = ['date', 'type', 'amount', 'currency', 'category', 'account', 'to_account', 'merchant', 'description', 'payment_method', 'status', 'id'];

    public function __construct(private readonly ReportService $reports) {}

    /** @return array{path: string, filename: string, rows: int, truncated: bool} */
    public function csv(User $user, Period $period, ?ReportQuery $filters = null): array
    {
        $dir = storage_path('app/private/exports');
        File::ensureDirectoryExists($dir, 0700);
        $path = $dir.'/'.Str::ulid().'.csv';

        $txs = $this->reports->transactions($user->id, $period, $filters, self::MAX_ROWS + 1);
        $truncated = $txs->count() > self::MAX_ROWS;
        $txs = $txs->take(self::MAX_ROWS)->sortBy([['occurred_on', 'asc'], ['created_at', 'asc']])->values();

        $accounts = LedgerAccount::where('user_id', $user->id)->pluck('name', 'id');
        $categories = Category::where('user_id', $user->id)->pluck('name', 'id');
        $merchants = Merchant::where('user_id', $user->id)->pluck('name', 'id');

        touch($path);
        chmod($path, 0600); // before any data is written
        $h = fopen($path, 'wb');
        fwrite($h, "\xEF\xBB\xBF");
        fputcsv($h, self::COLUMNS, ',', '"', '');

        foreach ($txs->chunk(500) as $chunk) {
            $entries = LedgerEntry::where('user_id', $user->id)->whereIn('transaction_id', $chunk->pluck('id'))->get()->groupBy('transaction_id');
            foreach ($chunk as $t) {
                /** @var LedgerTransaction $t */
                $e = $entries[$t->id] ?? collect();
                $debit = $e->firstWhere('direction', 'D');
                $credit = $e->firstWhere('direction', 'C');
                [$from, $to] = match ($t->type->value) {
                    'expense' => [$credit, null],
                    'income' => [$debit, null],
                    default => [$credit, $debit],
                };
                $categoryId = $e->whereNotNull('category_id')->first()?->category_id;

                fputcsv($h, [
                    $t->occurred_on->format('Y-m-d'), $t->type->value,
                    Money::ofMinor((int) $t->debit_total_minor, $t->currency)->toDecimal(), $t->currency,
                    $this->safe($categoryId ? ($categories[$categoryId] ?? '') : ''),
                    $this->safe($from ? ($accounts[$from->account_id] ?? '') : ''),
                    $this->safe($to ? ($accounts[$to->account_id] ?? '') : ''),
                    $this->safe($t->merchant_id ? ($merchants[$t->merchant_id] ?? '') : ''),
                    $this->safe((string) $t->description), $t->payment_method ?? '', $t->status->value, $t->id,
                ], ',', '"', '');
            }
        }
        fclose($h);

        $slug = Str::slug($period->label) ?: 'export';

        return ['path' => $path, 'filename' => "moneytalks-{$slug}.csv", 'rows' => $txs->count(), 'truncated' => $truncated];
    }

    /** Neutralise CSV/spreadsheet formula injection: text starting with = + - @ (or tab/CR) is prefixed with an apostrophe. */
    public function safe(string $v): string
    {
        return $v !== '' && preg_match('/^[\s\x{A0}]*[=+\-@]|^[\t\r]/u', $v) ? "'".$v : $v;
    }
}
