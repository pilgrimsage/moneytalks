<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Categories form a tree per user (system defaults are copied per user at provisioning).
        // `path` ("Food > Vegetables") gives a plain UNIQUE that also works for top-level rows,
        // where UNIQUE(user_id, parent_id, name) would not (NULL parent_id never collides in MySQL).
        Schema::create('categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('user_id', 26);
            $table->char('parent_id', 26)->nullable();
            $table->string('kind', 16); // expense | income
            $table->string('name', 100);
            $table->string('path', 191);
            $table->string('icon', 16)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps(6);

            $table->unique(['user_id', 'kind', 'path']);
            $table->index(['user_id', 'parent_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('parent_id')->references('id')->on('categories')->restrictOnDelete();
        });

        Schema::create('merchants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('user_id', 26);
            $table->string('name', 120);
            $table->char('default_category_id', 26)->nullable();
            $table->timestamps(6);

            $table->unique(['user_id', 'name']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('default_category_id')->references('id')->on('categories')->nullOnDelete();
        });

        Schema::create('counterparties', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('user_id', 26);
            $table->string('name', 120);
            $table->text('phone_enc')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps(6);

            $table->unique(['user_id', 'name']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // The user's personal vocabulary: "sabji" -> Vegetables, "cc" -> Credit Card, ...
        // entity_id is polymorphic by entity_type, so it has no FK; EntityResolver always scopes by user_id.
        Schema::create('user_aliases', function (Blueprint $table) {
            $table->id();
            $table->char('user_id', 26);
            $table->string('entity_type', 24); // category | merchant | account | counterparty
            $table->char('entity_id', 26);
            $table->string('alias', 120);      // normalised (see Text::normalize)
            $table->string('locale', 16)->nullable();
            $table->string('source', 16)->default('seed'); // seed | user | learned
            $table->unsignedInteger('use_count')->default(0);
            $table->timestamps(6);

            $table->unique(['user_id', 'entity_type', 'alias']);
            $table->index(['user_id', 'entity_type', 'entity_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_aliases');
        Schema::dropIfExists('counterparties');
        Schema::dropIfExists('merchants');
        Schema::dropIfExists('categories');
    }
};
