<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Raw, durable record of every webhook delivery. The DB (not the queue) is the source of truth:
        // anything not `processed` can be re-driven by the scheduled reaper.
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 24);
            $table->char('event_hash', 64)->unique();      // sha256 of the raw body: a redelivery is a no-op
            $table->longText('payload');                   // raw JSON; purged on a retention schedule
            $table->string('status', 16)->default('received'); // received | processing | processed | failed | ignored
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->dateTime('received_at', 6);
            $table->dateTime('processing_started_at', 6)->nullable();
            $table->dateTime('processed_at', 6)->nullable();

            $table->index(['status', 'received_at']);
        });

        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('user_id', 26)->nullable();       // null until the sender is resolved / for unknown senders
            $table->string('wa_message_id', 191)->nullable()->unique(); // Meta's id; THE idempotency key for inbound
            $table->string('dedupe_key', 191)->nullable()->unique();    // our key for outbound replies (retry-safe)
            $table->string('in_reply_to', 191)->nullable();
            $table->char('peer_bidx', 64)->nullable();     // blind index of the other party's number (never the number)
            $table->string('direction', 3);                // in | out
            $table->string('message_type', 24);            // text | audio | image | interactive | button | template | unsupported ...
            $table->text('text')->nullable();              // encrypted (cast)
            $table->longText('payload')->nullable();       // encrypted (cast)
            $table->string('status', 32);                  // see App\Enums\MessageStatus
            $table->string('error', 255)->nullable();
            $table->string('pricing_category', 32)->nullable();
            $table->string('pricing_model', 32)->nullable();
            $table->boolean('billable')->nullable();
            $table->unsignedBigInteger('meta_timestamp')->nullable(); // Meta's unix timestamp (ordering)
            $table->dateTime('received_at', 6)->nullable();
            $table->dateTime('processed_at', 6)->nullable();
            $table->dateTime('sent_at', 6)->nullable();
            $table->timestamps(6);

            $table->index(['user_id', 'created_at']);
            $table->index(['direction', 'status']);
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('webhook_events');
    }
};
