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
        Schema::table('department_employee', function (Blueprint $table): void {
            $table->index('employee_id', 'department_employee_employee_id_index');
        });
        Schema::table('media_asset_label', function (Blueprint $table): void {
            $table->index('media_label_id', 'media_asset_label_media_label_id_index');
        });
        Schema::table('ingredient_enrichment_batch_items', function (Blueprint $table): void {
            $table->index('ingredient_id', 'ingredient_enrichment_batch_items_ingredient_id_index');
        });
        Schema::table('ingredient_enrichment_batch_items', function (Blueprint $table): void {
            $table->index('ingredient_intake_item_id', 'ing_enrichment_batch_items_intake_item_id_index');
        });
        Schema::table('ingredient_intake_items', function (Blueprint $table): void {
            $table->index('existing_ingredient_id', 'ingredient_intake_items_existing_ingredient_id_index');
        });
        Schema::table('ingredient_intake_items', function (Blueprint $table): void {
            $table->index('promoted_ingredient_id', 'ingredient_intake_items_promoted_ingredient_id_index');
        });
        Schema::table('production_batch_ingredients', function (Blueprint $table): void {
            $table->index('ingredient_id', 'production_batch_ingredients_ingredient_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('production_batch_ingredients', function (Blueprint $table): void {
            $table->dropIndex('production_batch_ingredients_ingredient_id_index');
        });
        Schema::table('ingredient_intake_items', function (Blueprint $table): void {
            $table->dropIndex('ingredient_intake_items_promoted_ingredient_id_index');
        });
        Schema::table('ingredient_intake_items', function (Blueprint $table): void {
            $table->dropIndex('ingredient_intake_items_existing_ingredient_id_index');
        });
        Schema::table('ingredient_enrichment_batch_items', function (Blueprint $table): void {
            $table->dropIndex('ing_enrichment_batch_items_intake_item_id_index');
        });
        Schema::table('ingredient_enrichment_batch_items', function (Blueprint $table): void {
            $table->dropIndex('ingredient_enrichment_batch_items_ingredient_id_index');
        });
        Schema::table('media_asset_label', function (Blueprint $table): void {
            $table->dropIndex('media_asset_label_media_label_id_index');
        });
        Schema::table('department_employee', function (Blueprint $table): void {
            $table->dropIndex('department_employee_employee_id_index');
        });
    }
};
