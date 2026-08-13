<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredient_enrichment_batch_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('ingredient_enrichment_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->nullable()->constrained()->nullOnDelete();
            $table->string('catalog_key', 120);
            $table->string('status', 32)->index();
            $table->jsonb('snapshot');
            $table->string('source_fingerprint', 64);
            $table->jsonb('result')->nullable();
            $table->jsonb('validation_report')->nullable();
            $table->jsonb('plan')->nullable();
            $table->jsonb('replacement_fields')->nullable();
            $table->string('confidence', 16)->nullable();
            $table->jsonb('warnings')->nullable();
            $table->jsonb('unresolved_questions')->nullable();
            $table->jsonb('sources')->nullable();
            $table->string('provider_response_id', 100)->nullable();
            $table->string('provider_request_id', 100)->nullable();
            $table->string('provider_model', 100)->nullable();
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedInteger('web_search_calls')->default(0);
            $table->string('failure_code', 100)->nullable();
            $table->text('failure_message')->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('applied_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('research_started_at')->nullable();
            $table->timestampTz('research_completed_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('applied_at')->nullable();
            $table->timestampsTz();

            $table->unique(['ingredient_enrichment_batch_id', 'ingredient_id'], 'ingredient_enrichment_items_batch_ingredient_unique');
            $table->index(['ingredient_enrichment_batch_id', 'status'], 'ingredient_enrichment_items_batch_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_enrichment_batch_items');
    }
};
