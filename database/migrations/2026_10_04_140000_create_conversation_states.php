<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // At most ONE pending item per user (a question we asked, or something awaiting Confirm/Cancel).
        // Short-lived by design: it expires (expires_at) and is purged; the payload is encrypted.
        Schema::create('conversation_states', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('user_id', 26)->unique();
            $table->string('kind', 16);                    // clarify | confirm
            $table->longText('payload');                   // encrypted (cast)
            $table->string('source_wa_message_id', 191);   // the inbound message that created it (retry safety)
            $table->unsignedTinyInteger('turns')->default(0);
            $table->dateTime('expires_at', 6);
            $table->timestamps(6);

            $table->index('expires_at');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // Built-in prompts sync themselves into prompt_templates; admin-created rows are marked so they win.
        Schema::table('prompt_templates', function (Blueprint $table) {
            $table->string('source', 16)->default('admin')->after('status'); // builtin | admin
        });
    }

    public function down(): void
    {
        Schema::table('prompt_templates', fn (Blueprint $t) => $t->dropColumn('source'));
        Schema::dropIfExists('conversation_states');
    }
};
