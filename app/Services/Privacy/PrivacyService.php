<?php

namespace App\Services\Privacy;

use App\Domain\Ledger\AuditLogger;
use App\Models\Counterparty;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Privacy flows (docs/security.md). Console only: there is deliberately no way to export or erase data from a chat message.
 *
 * Export: everything the app holds about the user, as JSON (the user's own data, read with user_id scoping).
 *
 * Erasure: the ledger is append-only and may have to be kept for accounting, so erasure = removing personal data and
 * keeping the anonymous numbers. It deletes message bodies, AI bodies, aliases and pending conversations; blanks the
 * name and number (the number can never match again, so the same person can sign up fresh); renames every person to
 * "Person N"; clears the free-text description of every ledger transaction (the only editable column, not part of the
 * tamper-evidence hash); and marks the user deleted. Amounts, dates, accounts and categories remain.
 */
class PrivacyService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array<string, mixed> */
    public function export(User $user): array
    {
        $id = $user->id;
        $rows = fn (string $table, ?array $columns = null) => DB::table($table)->where('user_id', $id)->get($columns ?? ['*'])->map(fn ($r) => (array) $r)->all();

        return [
            'exported_at' => now()->toIso8601String(),
            'format' => 'moneytalks-export-v1',
            'user' => ['id' => $id, 'name' => $user->name, 'whatsapp_number' => $user->waId(), 'timezone' => $user->timezone, 'currency' => $user->base_currency, 'created_at' => (string) $user->created_at],
            'settings' => DB::table('user_settings')->where('user_id', $id)->first(),
            'accounts' => $rows('ledger_accounts'),
            'categories' => $rows('categories'),
            'merchants' => $rows('merchants'),
            'people' => $rows('counterparties', ['id', 'name', 'status']),
            'aliases' => $rows('user_aliases'),
            'transactions' => DB::table('ledger_transactions')->where('user_id', $id)->orderBy('occurred_on')->orderBy('id')->get()->map(fn ($t) => (array) $t + [
                'entries' => DB::table('ledger_entries')->where('transaction_id', $t->id)->orderBy('position')->get(['account_id', 'direction', 'amount_minor', 'currency', 'category_id'])->map(fn ($e) => (array) $e)->all(),
            ])->all(),
            'debts' => $rows('debt_records'),
            'debt_settlements' => $rows('debt_settlements'),
            'budgets' => $rows('budgets'),
            'goals' => $rows('goals'),
            'loans' => $rows('loans'),
            'recurring_rules' => $rows('recurring_rules'),
            'recurring_occurrences' => $rows('recurring_occurrences'),
        ];
    }

    /**
     * @return array<string, int> what was removed or cleared, by kind
     */
    public function erase(User $user): array
    {
        $id = $user->id;
        $wa = $user->waId();
        $counts = [];

        DB::transaction(function () use ($user, $id, $wa, &$counts) {
            // Messages: bodies and payloads go; the rows (ids, timestamps, status) stay for idempotency and billing reconciliation.
            $counts['messages cleared'] = DB::table('whatsapp_messages')->where(fn ($q) => $q->where('user_id', $id)->orWhere('peer_bidx', $user->wa_id_bidx))
                ->update(['text' => null, 'payload' => null]);
            // Raw webhook payloads carry the number. Delete the events that concern only this person; an event that also carries
            // someone else's messages stays (it is encrypted and cleared by the 14-day retention purge).
            $counts['webhook events deleted'] = 0;
            foreach (DB::table('webhook_events')->orderBy('id')->get(['id', 'payload']) as $event) {
                try {
                    $json = Crypt::decryptString($event->payload);
                } catch (\Throwable) {
                    continue; // already cleared, or not ours to read
                }
                preg_match_all('/"(?:from|recipient_id|wa_id)":"(\d+)"/', $json, $m);
                $numbers = array_unique($m[1]);
                if ($numbers !== [] && $numbers === [$wa]) {
                    $counts['webhook events deleted'] += DB::table('webhook_events')->where('id', $event->id)->delete();
                }
            }

            $counts['AI request bodies cleared'] = DB::table('ai_requests')->where('user_id', $id)->update(['input' => null, 'output' => null]);
            $counts['pending conversations deleted'] = DB::table('conversation_states')->where('user_id', $id)->delete();
            $counts['aliases deleted'] = DB::table('user_aliases')->where('user_id', $id)->delete();

            // People become anonymous; so do the per-person accounts and the names of plans.
            $n = 0;
            foreach (Counterparty::where('user_id', $id)->orderBy('id')->get() as $person) {
                $n++;
                $old = $person->name;
                $person->update(['name' => "Person {$n}", 'phone_enc' => null]);
                DB::table('ledger_accounts')->where('user_id', $id)->where('counterparty_id', $person->id)
                    ->update(['name' => DB::raw('CONCAT(SUBSTRING_INDEX(name, \': \', 1), \': Person '.$n.'\')')]);
                unset($old);
            }
            $counts['people anonymised'] = $n;
            foreach (['goals' => 'Goal', 'loans' => 'Loan', 'recurring_rules' => 'Recurring payment'] as $table => $label) {
                DB::table($table)->where('user_id', $id)->update(['name' => $label]);
            }
            DB::table('ledger_accounts')->where('user_id', $id)->where('name', 'like', 'Goal: %')->update(['name' => DB::raw("CONCAT('Goal: ', id)")]);
            DB::table('ledger_accounts')->where('user_id', $id)->where('name', 'like', 'Loan: %')->update(['name' => DB::raw("CONCAT('Loan: ', id)")]);
            DB::table('merchants')->where('user_id', $id)->update(['name' => DB::raw("CONCAT('Merchant ', id)")]);

            // The one permitted ledger edit: clearing free text.
            $counts['transaction descriptions cleared'] = DB::table('ledger_transactions')->where('user_id', $id)->whereNotNull('description')->update(['description' => null]);

            // The account holder: the number can never match again.
            DB::table('users')->where('id', $id)->update([
                'name' => null, 'status' => 'deleted',
                'wa_id_enc' => Crypt::encryptString('erased-'.Str::random(16)),
                'wa_id_bidx' => hash('sha256', 'erased-'.Str::random(32)),
                'updated_at' => now(),
            ]);

            $this->audit->record('user.erased', $id, 'user', $id, null, ['cleared' => $counts], 'erasure requested by the account holder', 'admin');
        });

        return $counts;
    }
}
