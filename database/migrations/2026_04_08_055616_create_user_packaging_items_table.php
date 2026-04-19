<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the user_packaging_items table.
 *
 * Each user builds their own reusable catalog of packaging materials (boxes, labels,
 * jars, etc.) with an effective unit price. When a packaging item is added to a recipe
 * costing, its name and price are snapshotted into the costing row so historical
 * costings remain accurate even if the catalog item is later edited or deleted.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_packaging_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('unit_cost', total: 18, places: 4);
            $table->string('currency', 3)->default('EUR');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_packaging_items');
    }
};
