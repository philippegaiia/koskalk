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
        Schema::table('stock_lots', function (Blueprint $table): void {
            $table->index('ingredient_id', 'stock_lots_ingredient_id_index');
            $table->index('packaging_item_id', 'stock_lots_packaging_item_id_index');
            $table->index('supplier_listing_id', 'stock_lots_supplier_listing_id_index');
            $table->index('recipe_id', 'stock_lots_recipe_id_index');
        });

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->index('reversal_of_stock_movement_id', 'stock_movements_reversal_of_stock_movement_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_lots', function (Blueprint $table): void {
            $table->dropIndex('stock_lots_ingredient_id_index');
            $table->dropIndex('stock_lots_packaging_item_id_index');
            $table->dropIndex('stock_lots_supplier_listing_id_index');
            $table->dropIndex('stock_lots_recipe_id_index');
        });

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropIndex('stock_movements_reversal_of_stock_movement_id_index');
        });
    }
};
