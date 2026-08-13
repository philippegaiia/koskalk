<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredient_enrichment_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->index();
            $table->uuid('laravel_batch_id')->nullable()->index();
            $table->string('model', 100);
            $table->string('reasoning_effort', 32);
            $table->string('prompt_version', 100);
            $table->unsignedSmallInteger('schema_version');
            $table->string('mode', 32)->default('fill_missing');
            $table->unsignedInteger('total_count')->default(0);
            $table->unsignedInteger('pending_count')->default(0);
            $table->unsignedInteger('researching_count')->default(0);
            $table->unsignedInteger('ready_count')->default(0);
            $table->unsignedInteger('warning_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('approved_count')->default(0);
            $table->unsignedInteger('applied_count')->default(0);
            $table->unsignedInteger('unchanged_count')->default(0);
            $table->unsignedInteger('stale_count')->default(0);
            $table->unsignedInteger('cancelled_count')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedInteger('web_search_calls')->default(0);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_enrichment_batches');
    }
};
