<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the recipe_version_costing_packaging_items table.
 *
 * Packaging rows are snapshotted into each costing so historical costings stay
 * readable even if the user later edits or deletes the source catalog item.
 * The nullable user_packaging_item_id link allows the UI to suggest updates
 * but is not required for the costing row to display correctly.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('recipe_version_costing_packaging_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_version_costing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_packaging_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->decimal('unit_cost', total: 10, places: 4);
            $table->decimal('quantity', total: 10, places: 3)->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recipe_version_costing_packaging_items');
    }
};
