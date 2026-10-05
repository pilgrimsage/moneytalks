<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // End users authenticate via their WhatsApp number (verified by Meta), not a password.
        // The number is stored encrypted; lookups use an HMAC blind index (docs/decisions.md E3).
        Schema::create('users', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->text('wa_id_enc');
            $table->char('wa_id_bidx', 64)->unique();
            $table->string('name')->nullable();
            $table->string('timezone', 64)->default('Asia/Kolkata');
            $table->char('base_currency', 3)->default('INR');
            $table->string('locale', 16)->default('en-IN');
            $table->string('language', 16)->default('en');
            $table->string('status', 32)->default('active'); // pending_consent|onboarding|active|suspended|deleted
            $table->string('onboarding_step', 32)->nullable();
            $table->dateTime('last_inbound_at', 6)->nullable();
            $table->timestamps(6);
        });

        Schema::create('user_settings', function (Blueprint $table) {
            $table->char('user_id', 26)->primary();
            $table->char('default_account_id', 26)->nullable();
            $table->json('notification_prefs')->nullable();
            $table->unsignedBigInteger('confirm_threshold_minor')->nullable();
            $table->unsignedInteger('duplicate_window_seconds')->default(120);
            $table->json('feature_overrides')->nullable();
            $table->timestamps(6);

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->char('user_id', 26)->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('user_settings');
        Schema::dropIfExists('users');
    }
};
