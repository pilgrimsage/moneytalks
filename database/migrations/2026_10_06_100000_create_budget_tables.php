<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A monthly spending limit: for one expense category (and its sub-categories), or overall when category_id is null.
        Schema::create('budgets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('user_id', 26);
            $table->char('category_id', 26)->nullable();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 12)->default('active');   // active | removed
            $table->timestamps(6);

            $table->index(['user_id', 'status']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('category_id')->references('id')->on('categories')->restrictOnDelete();
        });

        // One row per budget, month and threshold actually announced, so an alert is never sent twice.
        Schema::create('budget_alerts', function (Blueprint $table) {
            $table->id();
            $table->char('user_id', 26);
            $table->char('budget_id', 26);
            $table->char('month', 7);                           // YYYY-MM (user-local)
            $table->unsignedSmallInteger('threshold');          // 80 | 100
            $table->timestamps(6);

            $table->unique(['budget_id', 'month', 'threshold']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('budget_id')->references('id')->on('budgets')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE budgets ADD CONSTRAINT chk_budget_amount CHECK (amount_minor > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_alerts');
        Schema::dropIfExists('budgets');
    }
};
