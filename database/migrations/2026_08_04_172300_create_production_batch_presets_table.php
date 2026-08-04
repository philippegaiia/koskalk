<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('production_batch_presets', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->decimal('basis_quantity_grams', 20, 9);
            $table->decimal('basis_input_value', 20, 9);
            $table->string('basis_input_unit', 24);
            $table->unsignedBigInteger('expected_units');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['workspace_id', 'recipe_id', 'is_active']);
            $table->index(['recipe_id', 'is_default']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX production_batch_presets_one_default_per_recipe ON production_batch_presets (recipe_id) WHERE is_default = TRUE',
            );
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement(
                'CREATE UNIQUE INDEX production_batch_presets_one_default_per_recipe ON production_batch_presets (recipe_id) WHERE is_default = 1',
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_batch_presets');
    }
};
