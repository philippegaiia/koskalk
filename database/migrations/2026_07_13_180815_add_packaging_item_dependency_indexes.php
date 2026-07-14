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
        Schema::table('recipe_version_packaging_items', function (Blueprint $table): void {
            $table->index('user_packaging_item_id');
        });

        Schema::table('recipe_version_costing_packaging_items', function (Blueprint $table): void {
            $table->index('user_packaging_item_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recipe_version_costing_packaging_items', function (Blueprint $table): void {
            $table->dropIndex(['user_packaging_item_id']);
        });

        Schema::table('recipe_version_packaging_items', function (Blueprint $table): void {
            $table->dropIndex(['user_packaging_item_id']);
        });
    }
};
