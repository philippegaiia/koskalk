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
        Schema::table('ingredient_enrichment_batches', function (Blueprint $table): void {
            $table->unsignedInteger('structured_source_calls')->default(0);
        });

        Schema::table('ingredient_enrichment_batch_items', function (Blueprint $table): void {
            $table->jsonb('research_stages')->default('{}');
            $table->jsonb('original_result')->nullable();
            $table->jsonb('edited_fields')->nullable();
            $table->foreignId('edited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('edited_at')->nullable();
            $table->unsignedInteger('structured_source_calls')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ingredient_enrichment_batch_items', function (Blueprint $table): void {
            $table->dropForeign(['edited_by_user_id']);
            $table->dropColumn([
                'research_stages',
                'original_result',
                'edited_fields',
                'edited_by_user_id',
                'edited_at',
                'structured_source_calls',
            ]);
        });

        Schema::table('ingredient_enrichment_batches', function (Blueprint $table): void {
            $table->dropColumn('structured_source_calls');
        });
    }
};
