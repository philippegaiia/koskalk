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
        if (! Schema::hasTable('ingredient_substance_entries') && Schema::hasTable('ingredient_regulated_substance_entries')) {
            Schema::rename('ingredient_regulated_substance_entries', 'ingredient_substance_entries');
        }

        if (Schema::hasTable('ingredient_substance_entries')) {
            if (
                Schema::hasColumn('ingredient_substance_entries', 'regulated_substance_id')
                && ! Schema::hasColumn('ingredient_substance_entries', 'substance_id')
            ) {
                Schema::table('ingredient_substance_entries', function (Blueprint $table): void {
                    $table->renameColumn('regulated_substance_id', 'substance_id');
                });
            }

            return;
        }

        Schema::create('ingredient_substance_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('substance_id')->constrained('substance_catalog')->cascadeOnDelete();
            $table->decimal('concentration_percent', 8, 5)->nullable();
            $table->string('concentration_source', 32)->default('unknown');
            $table->text('source_notes')->nullable();
            $table->json('source_data')->nullable();
            $table->timestamps();

            $table->unique(['ingredient_id', 'substance_id']);
            $table->index(['substance_id', 'concentration_source']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ingredient_substance_entries') && ! Schema::hasTable('ingredient_regulated_substance_entries')) {
            if (
                Schema::hasColumn('ingredient_substance_entries', 'substance_id')
                && ! Schema::hasColumn('ingredient_substance_entries', 'regulated_substance_id')
            ) {
                Schema::table('ingredient_substance_entries', function (Blueprint $table): void {
                    $table->renameColumn('substance_id', 'regulated_substance_id');
                });
            }

            Schema::rename('ingredient_substance_entries', 'ingredient_regulated_substance_entries');

            return;
        }

        Schema::dropIfExists('ingredient_substance_entries');
    }
};
