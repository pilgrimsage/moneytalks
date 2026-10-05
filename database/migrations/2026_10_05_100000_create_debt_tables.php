<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Debts between the user and other people (docs/ledger.md sections 3.6-3.10). These tables are DERIVED bookkeeping
 * (due dates, which loan a repayment settles); the ledger stays the source of truth for the money itself, and the
 * verifier checks that the two agree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debt_records', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('user_id', 26);
            $table->char('counterparty_id', 26);
            $table->string('direction', 12);                  // receivable (they owe me) | payable (I owe them)
            $table->char('opened_transaction_id', 26);
            $table->bigInteger('original_minor');
            $table->date('due_on')->nullable();
            $table->string('status', 16)->default('open');    // open | partial | settled | voided
            $table->timestamps(6);

            $table->index(['user_id', 'counterparty_id', 'direction', 'status']);
            $table->index('opened_transaction_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('counterparty_id')->references('id')->on('counterparties')->restrictOnDelete();
            $table->foreign('opened_transaction_id')->references('id')->on('ledger_transactions')->restrictOnDelete();
        });

        Schema::create('debt_settlements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('user_id', 26);
            $table->char('debt_record_id', 26);
            $table->char('transaction_id', 26);
            $table->bigInteger('amount_minor');
            $table->dateTime('voided_at', 6)->nullable();      // set when the repayment is undone; rows are never deleted
            $table->timestamps(6);

            $table->index('debt_record_id');
            $table->index('transaction_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('debt_record_id')->references('id')->on('debt_records')->restrictOnDelete();
            $table->foreign('transaction_id')->references('id')->on('ledger_transactions')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE debt_records ADD CONSTRAINT chk_debt_amount CHECK (original_minor > 0)');
        DB::statement('ALTER TABLE debt_settlements ADD CONSTRAINT chk_debt_settle_amount CHECK (amount_minor > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_settlements');
        Schema::dropIfExists('debt_records');
    }
};
