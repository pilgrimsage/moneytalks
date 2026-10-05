<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A repeating payment (subscription, rent, salary). A rule never posts anything by itself: it creates an
        // occurrence when due, the user confirms it ("Paid"), and only then does the ledger get an ordinary transaction.
        Schema::create('recurring_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('user_id', 26);
            $table->string('name', 120);
            $table->string('type', 12);                       // expense | income
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->char('category_id', 26);
            $table->char('merchant_id', 26)->nullable();
            $table->char('account_id', 26);
            $table->string('frequency', 12);                  // daily | weekly | monthly | yearly
            $table->date('anchor_on');                        // first due date; later dates are anchor + cycle * step (so the 31st survives short months)
            $table->unsignedInteger('cycle')->default(0);     // index of the NEXT occurrence
            $table->date('next_due_on');
            $table->string('status', 12)->default('active');  // active | cancelled
            $table->timestamps(6);

            $table->index(['user_id', 'status', 'next_due_on']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('category_id')->references('id')->on('categories')->restrictOnDelete();
            $table->foreign('account_id')->references('id')->on('ledger_accounts')->restrictOnDelete();
        });

        Schema::create('recurring_occurrences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('user_id', 26);
            $table->char('rule_id', 26);
            $table->date('due_on');
            $table->string('status', 12)->default('due');     // due | paid | skipped | cancelled
            $table->char('transaction_id', 26)->nullable();
            $table->unsignedTinyInteger('reminders_sent')->default(0);
            $table->dateTime('last_reminded_at', 6)->nullable();
            $table->timestamps(6);

            $table->unique(['rule_id', 'due_on']);
            $table->index(['user_id', 'status']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('rule_id')->references('id')->on('recurring_rules')->cascadeOnDelete();
            $table->foreign('transaction_id')->references('id')->on('ledger_transactions')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE recurring_rules ADD CONSTRAINT chk_recurring_amount CHECK (amount_minor > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_occurrences');
        Schema::dropIfExists('recurring_rules');
    }
};
