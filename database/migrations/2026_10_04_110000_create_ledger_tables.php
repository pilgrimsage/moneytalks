<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('user_id', 26);
            $table->string('kind', 16);     // asset | liability | income | expense | equity
            $table->string('subtype', 24);  // cash | bank | wallet | credit_card | loan | receivable | payable | investment | goal | system
            $table->string('name', 120);
            $table->char('currency', 3);
            $table->char('counterparty_id', 26)->nullable();
            $table->boolean('is_system')->default(false);
            $table->string('status', 16)->default('active'); // active | closed
            $table->json('meta')->nullable();
            $table->timestamps(6);

            $table->unique(['user_id', 'name']);
            $table->unique(['user_id', 'counterparty_id', 'kind']); // one receivable/payable per person
            $table->unique(['user_id', 'id']);                      // target of the composite FK from entries
            $table->index(['user_id', 'subtype']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('counterparty_id')->references('id')->on('counterparties')->restrictOnDelete();
        });

        Schema::table('user_settings', function (Blueprint $table) {
            $table->foreign('default_account_id')->references('id')->on('ledger_accounts')->nullOnDelete();
        });

        Schema::create('ledger_transactions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('user_id', 26);
            $table->string('type', 24);
            $table->string('status', 16)->default('posted'); // posted | reversed | voided
            $table->date('occurred_on');                     // user-local business date
            $table->dateTime('occurred_at', 6);              // UTC
            $table->string('description', 255)->nullable();
            $table->string('payment_method', 24)->nullable();
            $table->string('source', 24)->default('manual');
            $table->char('merchant_id', 26)->nullable();
            $table->char('counterparty_id', 26)->nullable();
            $table->string('wa_message_id', 191)->nullable();
            $table->string('idempotency_key', 191);
            $table->decimal('confidence', 4, 3)->nullable();
            $table->char('reversal_of_id', 26)->nullable();
            $table->char('reversed_by_id', 26)->nullable();
            $table->char('corrects_id', 26)->nullable();
            $table->char('related_transaction_id', 26)->nullable();
            $table->char('currency', 3);
            $table->char('base_currency', 3);
            $table->bigInteger('base_amount_minor');
            $table->decimal('exchange_rate', 20, 10)->default(1);
            $table->bigInteger('debit_total_minor');
            $table->bigInteger('credit_total_minor');
            $table->char('entries_hash', 64);
            $table->timestamps(6);

            $table->unique('idempotency_key');
            $table->index(['user_id', 'occurred_on']);
            $table->index(['user_id', 'type', 'occurred_on']);
            $table->index(['user_id', 'merchant_id', 'occurred_on']);
            $table->index(['user_id', 'status']);
            $table->index('wa_message_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('merchant_id')->references('id')->on('merchants')->restrictOnDelete();
            $table->foreign('counterparty_id')->references('id')->on('counterparties')->restrictOnDelete();
            $table->foreign('reversal_of_id')->references('id')->on('ledger_transactions')->restrictOnDelete();
            $table->foreign('reversed_by_id')->references('id')->on('ledger_transactions')->restrictOnDelete();
            $table->foreign('corrects_id')->references('id')->on('ledger_transactions')->restrictOnDelete();
            $table->foreign('related_transaction_id')->references('id')->on('ledger_transactions')->restrictOnDelete();
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->char('transaction_id', 26);
            $table->char('user_id', 26);
            $table->char('account_id', 26);
            $table->char('direction', 1);          // D | C
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->char('category_id', 26)->nullable(); // dimension on the income/expense side
            $table->unsignedSmallInteger('position');
            $table->timestamp('created_at', 6)->useCurrent();

            $table->index(['account_id', 'id']);                          // balances
            $table->index(['user_id', 'category_id', 'transaction_id']);  // category reports
            $table->index('transaction_id');
            $table->foreign('transaction_id')->references('id')->on('ledger_transactions')->restrictOnDelete();
            $table->foreign(['user_id', 'account_id'])->references(['user_id', 'id'])->on('ledger_accounts')->restrictOnDelete();
            $table->foreign('category_id')->references('id')->on('categories')->restrictOnDelete();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type', 16);       // user | admin | system
            $table->string('actor_id', 64)->nullable();
            $table->char('user_id', 26)->nullable();
            $table->string('action', 64);
            $table->string('subject_type', 64)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('reason', 255)->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->timestamp('created_at', 6)->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        // Hard guarantees that MySQL and MariaDB both enforce (8.0.16+ / 10.2+).
        DB::statement('ALTER TABLE ledger_transactions ADD CONSTRAINT chk_ledger_tx_balanced CHECK (debit_total_minor = credit_total_minor AND debit_total_minor > 0)');
        DB::statement("ALTER TABLE ledger_transactions ADD CONSTRAINT chk_ledger_tx_status CHECK (status IN ('posted','reversed','voided'))");
        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT chk_ledger_entry_dir CHECK (direction IN ('D','C'))");
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT chk_ledger_entry_amount CHECK (amount_minor > 0)');
        DB::statement("ALTER TABLE ledger_accounts ADD CONSTRAINT chk_ledger_acct_kind CHECK (kind IN ('asset','liability','income','expense','equity'))");
    }

    public function down(): void
    {
        Schema::table('user_settings', fn (Blueprint $t) => $t->dropForeign(['default_account_id']));
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('ledger_transactions');
        Schema::dropIfExists('ledger_accounts');
    }
};
