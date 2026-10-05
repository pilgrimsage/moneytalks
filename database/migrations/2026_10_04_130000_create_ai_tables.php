<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Versioned prompts: never hard-coded without a version (docs/ai.md section 3).
        Schema::create('prompt_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64);
            $table->string('version', 16);
            $table->string('status', 16)->default('draft'); // draft | active | retired
            $table->longText('body');
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->timestamps(6);

            $table->unique(['name', 'version']);
        });

        // Prices live in data, not code. Micro-dollars per million tokens: $1.00/MTok = 1_000_000.
        Schema::create('ai_model_prices', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 24);
            $table->string('model', 64);
            $table->unsignedBigInteger('input_micros_per_mtok');
            $table->unsignedBigInteger('output_micros_per_mtok');
            $table->unsignedBigInteger('cache_read_micros_per_mtok')->default(0);
            $table->unsignedBigInteger('cache_write_micros_per_mtok')->default(0);
            $table->char('currency', 3)->default('USD');
            $table->date('effective_from');
            $table->timestamps(6);

            $table->unique(['provider', 'model', 'effective_from']);
        });

        Schema::create('ai_requests', function (Blueprint $table) {
            $table->id();
            $table->char('user_id', 26)->nullable();
            $table->char('whatsapp_message_id', 26)->nullable();
            $table->string('provider', 24);
            $table->string('model', 64);
            $table->string('request_type', 48);
            $table->unsignedBigInteger('prompt_template_id')->nullable();
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->unsignedBigInteger('estimated_cost_micros')->default(0); // micro-currency units
            $table->char('currency', 3)->default('USD');
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('status', 24);                 // ok | failed | refusal | truncated | invalid_json
            $table->string('error_code', 64)->nullable();
            $table->string('provider_request_id', 64)->nullable();
            $table->longText('input')->nullable();        // encrypted (cast), purged after retention_days
            $table->longText('output')->nullable();       // encrypted (cast), purged after retention_days
            $table->string('outcome', 24)->nullable();    // filled by interpretation: record | clarify | unsupported | ...
            $table->timestamp('created_at', 6)->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['model', 'created_at']);
            $table->index(['request_type', 'created_at']);
            $table->index('whatsapp_message_id');
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('prompt_template_id')->references('id')->on('prompt_templates')->nullOnDelete();
        });

        // Published Anthropic list prices at the time of writing (verify before relying on cost numbers).
        // Cache reads are 0.1x and 5-minute cache writes 1.25x of the input price.
        $rows = [
            ['claude-haiku-4-5', 1.00, 5.00],
            ['claude-sonnet-5-5', 2.00, 10.00],
            ['claude-opus-5-5', 4.00, 20.00],
        ];
        foreach ($rows as [$model, $in, $out]) {
            DB::table('ai_model_prices')->insert([
                'provider' => 'anthropic', 'model' => $model,
                'input_micros_per_mtok' => (int) round($in * 1_000_000),
                'output_micros_per_mtok' => (int) round($out * 1_000_000),
                'cache_read_micros_per_mtok' => (int) round($in * 0.1 * 1_000_000),
                'cache_write_micros_per_mtok' => (int) round($in * 1.25 * 1_000_000),
                'currency' => 'USD', 'effective_from' => '2026-01-01',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_requests');
        Schema::dropIfExists('ai_model_prices');
        Schema::dropIfExists('prompt_templates');
    }
};
