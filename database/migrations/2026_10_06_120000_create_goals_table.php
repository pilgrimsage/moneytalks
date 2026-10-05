<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A savings goal is a target on top of a "goal" asset account: saving = transferring money into that account.
        Schema::create('goals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('user_id', 26);
            $table->char('account_id', 26);
            $table->string('name', 80);
            $table->bigInteger('target_minor');
            $table->char('currency', 3);
            $table->date('target_date')->nullable();
            $table->string('status', 12)->default('active');   // active | achieved | cancelled
            $table->timestamps(6);

            $table->unique(['user_id', 'account_id']);
            $table->index(['user_id', 'status']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('account_id')->references('id')->on('ledger_accounts')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE goals ADD CONSTRAINT chk_goal_target CHECK (target_minor > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('goals');
    }
};
