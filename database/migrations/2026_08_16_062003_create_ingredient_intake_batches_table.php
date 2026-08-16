<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ingredient_intake_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->index();
            $table->string('name', 255);
            $table->text('notes')->nullable();
            $table->string('input_method', 32);
            $table->string('family_hint', 64)->nullable();
            $table->boolean('allow_gap_research')->default(false);
            $table->string('original_filename', 255)->nullable();
            $table->string('storage_disk', 100)->nullable();
            $table->string('storage_path', 1000)->nullable();
            $table->foreignId('ingredient_enrichment_batch_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->unsignedInteger('total_count')->default(0);
            $table->unsignedInteger('draft_count')->default(0);
            $table->unsignedInteger('needs_resolution_count')->default(0);
            $table->unsignedInteger('queued_count')->default(0);
            $table->unsignedInteger('researching_count')->default(0);
            $table->unsignedInteger('ready_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('approved_count')->default(0);
            $table->unsignedInteger('promoted_count')->default(0);
            $table->unsignedInteger('rejected_count')->default(0);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ingredient_intake_batches');
    }
};
