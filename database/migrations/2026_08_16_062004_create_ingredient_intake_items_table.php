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
        Schema::create('ingredient_intake_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('ingredient_intake_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('original_current_name', 255)->nullable();
            $table->string('normalized_current_name', 255)->nullable();
            $table->string('original_inci_name', 255)->nullable();
            $table->string('normalized_inci_name', 255)->nullable();
            $table->string('status', 32)->index();
            $table->jsonb('duplicate_candidates')->nullable();
            $table->string('duplicate_resolution', 32)->nullable();
            $table->foreignId('existing_ingredient_id')->nullable()->constrained('ingredients')->restrictOnDelete();
            $table->foreignId('promoted_ingredient_id')->nullable()->constrained('ingredients')->restrictOnDelete();
            $table->string('failure_code', 100)->nullable();
            $table->text('failure_message')->nullable();
            $table->foreignId('promoted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('promoted_at')->nullable();
            $table->timestamps();

            $table->unique(['ingredient_intake_batch_id', 'row_number'], 'ingredient_intake_items_batch_row_unique');
            $table->index(['ingredient_intake_batch_id', 'status'], 'ingredient_intake_items_batch_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ingredient_intake_items');
    }
};
