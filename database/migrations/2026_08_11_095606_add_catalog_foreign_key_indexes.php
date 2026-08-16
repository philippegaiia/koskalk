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
        Schema::table('ingredient_fatty_acids', function (Blueprint $table): void {
            $table->index('fatty_acid_id', 'ingredient_fatty_acids_fatty_acid_id_index');
        });

        Schema::table('current_material_prices', function (Blueprint $table): void {
            $table->index('ingredient_id', 'current_material_prices_ingredient_id_index');
            $table->index('packaging_item_id', 'current_material_prices_packaging_item_id_index');
        });

        Schema::table('ingredient_function_ingredient', function (Blueprint $table): void {
            $table->index('ingredient_function_id', 'ingredient_function_ingredient_ingredient_function_id_index');
        });

        Schema::table('recipe_versions', function (Blueprint $table): void {
            $table->index('ifra_product_category_id', 'recipe_versions_ifra_product_category_id_index');
            $table->index('regulatory_regime_id', 'recipe_versions_regulatory_regime_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ingredient_fatty_acids', function (Blueprint $table): void {
            $table->dropIndex('ingredient_fatty_acids_fatty_acid_id_index');
        });

        Schema::table('current_material_prices', function (Blueprint $table): void {
            $table->dropIndex('current_material_prices_ingredient_id_index');
            $table->dropIndex('current_material_prices_packaging_item_id_index');
        });

        Schema::table('ingredient_function_ingredient', function (Blueprint $table): void {
            $table->dropIndex('ingredient_function_ingredient_ingredient_function_id_index');
        });

        Schema::table('recipe_versions', function (Blueprint $table): void {
            $table->dropIndex('recipe_versions_ifra_product_category_id_index');
            $table->dropIndex('recipe_versions_regulatory_regime_id_index');
        });
    }
};
