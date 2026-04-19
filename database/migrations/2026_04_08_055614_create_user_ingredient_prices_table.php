<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the user_ingredient_prices table.
 *
 * Each user keeps their own private price per ingredient, including shared catalog
 * ingredients. This avoids duplicating ingredient records just to store a buying price.
 * The last_used_at column tracks when the price was most recently used, useful for
 * future UI features like "recently priced" sorting.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_ingredient_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->decimal('price_per_kg', total: 18, places: 4)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'ingredient_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_ingredient_prices');
    }
};
