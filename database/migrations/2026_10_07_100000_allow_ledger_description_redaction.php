<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Privacy erasure (docs/security.md): a ledger transaction's free-text `description` (typed by the user, may name people)
 * may be CLEARED to NULL, and nothing else may change. Amounts, accounts, dates and the entries hash stay immutable, so
 * the books still balance and tamper evidence still works; the description is not part of the hash.
 * Like the other triggers this is best-effort on hosts without the TRIGGER privilege (the application guards still apply).
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            DB::unprepared('DROP TRIGGER IF EXISTS ledger_tx_limited_update');
            DB::unprepared(<<<'SQL'
CREATE TRIGGER ledger_tx_limited_update BEFORE UPDATE ON ledger_transactions FOR EACH ROW
IF NOT (NEW.id <=> OLD.id AND NEW.user_id <=> OLD.user_id AND NEW.type <=> OLD.type
    AND NEW.occurred_on <=> OLD.occurred_on AND NEW.occurred_at <=> OLD.occurred_at
    AND (NEW.description <=> OLD.description OR NEW.description IS NULL) AND NEW.payment_method <=> OLD.payment_method
    AND NEW.source <=> OLD.source AND NEW.merchant_id <=> OLD.merchant_id
    AND NEW.counterparty_id <=> OLD.counterparty_id AND NEW.wa_message_id <=> OLD.wa_message_id
    AND NEW.idempotency_key <=> OLD.idempotency_key AND NEW.reversal_of_id <=> OLD.reversal_of_id
    AND NEW.corrects_id <=> OLD.corrects_id AND NEW.related_transaction_id <=> OLD.related_transaction_id
    AND NEW.currency <=> OLD.currency AND NEW.base_amount_minor <=> OLD.base_amount_minor
    AND NEW.debit_total_minor <=> OLD.debit_total_minor AND NEW.credit_total_minor <=> OLD.credit_total_minor
    AND NEW.base_currency <=> OLD.base_currency AND NEW.exchange_rate <=> OLD.exchange_rate
    AND NEW.confidence <=> OLD.confidence AND NEW.entries_hash <=> OLD.entries_hash) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'only status, reversed_by_id and clearing the description of a ledger transaction may change';
END IF
SQL);
        } catch (Throwable $e) {
            Log::warning('Could not update the ledger_tx_limited_update trigger; relying on application guards.', ['error' => $e->getMessage()]);
        }
    }

    public function down(): void
    {
        // The previous (stricter) definition lives in 2026_10_04_110100_create_ledger_immutability_triggers.php.
    }
};
