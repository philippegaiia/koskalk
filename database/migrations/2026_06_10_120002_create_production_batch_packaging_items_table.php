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
        Schema::create('production_batch_packaging_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_packaging_item_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('position');
            $table->string('name');
            $table->decimal('components_per_unit', total: 10, places: 3);
            $table->decimal('unit_cost', total: 18, places: 4);
            $table->decimal('cost_per_finished_unit', total: 18, places: 4)->default(0);
            $table->decimal('line_cost', total: 18, places: 4)->default(0);
            $table->timestamps();

            $table->index(['production_batch_id', 'position'], 'production_batch_packaging_items_order_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_batch_packaging_items');
    }
};
