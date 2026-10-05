<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Best-effort database-level immutability. Shared hosts often withhold the TRIGGER privilege (or
 * require SUPER when binary logging is on), so a failure here is logged, not fatal: the Eloquent
 * guards still apply, and `moneytalks:ledger:verify` detects tampering either way.
 * (docs/decisions.md section H)
 */
return new class extends Migration
{
    public function up(): void
    {
        $triggers = [
            'ledger_entries_no_update' => "CREATE TRIGGER ledger_entries_no_update BEFORE UPDATE ON ledger_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_entries are immutable'",
            'ledger_entries_no_delete' => "CREATE TRIGGER ledger_entries_no_delete BEFORE DELETE ON ledger_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_entries are immutable'",
            'ledger_tx_no_delete' => "CREATE TRIGGER ledger_tx_no_delete BEFORE DELETE ON ledger_transactions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_transactions cannot be deleted'",
            // Only status and reversed_by_id may change on a transaction (plus updated_at).
            'ledger_tx_limited_update' => <<<'SQL'
CREATE TRIGGER ledger_tx_limited_update BEFORE UPDATE ON ledger_transactions FOR EACH ROW
IF NOT (NEW.id <=> OLD.id AND NEW.user_id <=> OLD.user_id AND NEW.type <=> OLD.type
    AND NEW.occurred_on <=> OLD.occurred_on AND NEW.occurred_at <=> OLD.occurred_at
    AND NEW.description <=> OLD.description AND NEW.payment_method <=> OLD.payment_method
    AND NEW.source <=> OLD.source AND NEW.merchant_id <=> OLD.merchant_id
    AND NEW.counterparty_id <=> OLD.counterparty_id AND NEW.wa_message_id <=> OLD.wa_message_id
    AND NEW.idempotency_key <=> OLD.idempotency_key AND NEW.reversal_of_id <=> OLD.reversal_of_id
    AND NEW.corrects_id <=> OLD.corrects_id AND NEW.related_transaction_id <=> OLD.related_transaction_id
    AND NEW.currency <=> OLD.currency AND NEW.base_amount_minor <=> OLD.base_amount_minor
    AND NEW.debit_total_minor <=> OLD.debit_total_minor AND NEW.credit_total_minor <=> OLD.credit_total_minor
    AND NEW.base_currency <=> OLD.base_currency AND NEW.exchange_rate <=> OLD.exchange_rate
    AND NEW.confidence <=> OLD.confidence AND NEW.entries_hash <=> OLD.entries_hash) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'only status and reversed_by_id of a ledger transaction may change';
END IF
SQL,
            'audit_logs_no_update' => "CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs are append-only'",
            'audit_logs_no_delete' => "CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs are append-only'",
        ];

        foreach ($triggers as $name => $sql) {
            try {
                DB::unprepared($sql);
            } catch (Throwable $e) {
                Log::warning("Could not install DB trigger {$name}; relying on application guards.", ['error' => $e->getMessage()]);
            }
        }
    }

    public function down(): void
    {
        foreach (['ledger_entries_no_update', 'ledger_entries_no_delete', 'ledger_tx_no_delete', 'ledger_tx_limited_update', 'audit_logs_no_update', 'audit_logs_no_delete'] as $name) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
        }
    }
};
