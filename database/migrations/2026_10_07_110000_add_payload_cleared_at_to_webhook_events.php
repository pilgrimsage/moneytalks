<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Set by the retention purge when the raw (encrypted) payload has been replaced by an empty one.
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->dateTime('payload_cleared_at', 6)->nullable()->after('processed_at');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_events', fn (Blueprint $table) => $table->dropColumn('payload_cleared_at'));
    }
};
