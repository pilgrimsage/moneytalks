<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A loan the user is repaying by EMI. The outstanding amount is the balance of its liability account (never stored here).
        Schema::create('loans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('user_id', 26);
            $table->char('account_id', 26);
            $table->string('name', 80);
            $table->unsignedInteger('rate_bp');                 // annual interest in basis points (10.5% = 1050)
            $table->bigInteger('emi_minor');
            $table->bigInteger('opening_minor');                // outstanding when tracking started
            $table->date('started_on');
            $table->string('status', 12)->default('active');    // active | closed
            $table->timestamps(6);

            $table->unique(['user_id', 'account_id']);
            $table->index(['user_id', 'status']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('account_id')->references('id')->on('ledger_accounts')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE loans ADD CONSTRAINT chk_loan_emi CHECK (emi_minor > 0 AND opening_minor > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
