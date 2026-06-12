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
        Schema::create('production_batch_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('raw_material_lot_id')->nullable()->index();
            $table->string('phase_key', 64);
            $table->string('phase_name');
            $table->unsignedInteger('position');
            $table->string('ingredient_name');
            $table->decimal('percentage', total: 9, places: 4);
            $table->decimal('quantity', total: 12, places: 4);
            $table->string('unit', 16);
            $table->decimal('price_per_kg', total: 18, places: 4)->nullable();
            $table->decimal('line_cost', total: 18, places: 4)->default(0);
            $table->string('ingredient_lot_number', 120)->nullable();
            $table->timestamps();

            $table->index(['production_batch_id', 'phase_key', 'position'], 'production_batch_ingredients_order_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_batch_ingredients');
    }
};
