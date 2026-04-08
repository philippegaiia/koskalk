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
        Schema::create('recipe_version_costing_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_version_costing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->string('phase_key', 64);
            $table->unsignedInteger('position');
            $table->decimal('price_per_kg', total: 10, places: 4)->nullable();
            $table->timestamps();

            $table->unique(['recipe_version_costing_id', 'ingredient_id', 'phase_key', 'position'], 'recipe_version_costing_items_unique_row');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recipe_version_costing_items');
    }
};
