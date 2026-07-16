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
        Schema::table('recipe_items', function (Blueprint $table) {
            $table->index('ingredient_id');
        });

        Schema::table('recipe_version_costing_items', function (Blueprint $table) {
            $table->index('ingredient_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recipe_items', function (Blueprint $table) {
            $table->dropIndex(['ingredient_id']);
        });

        Schema::table('recipe_version_costing_items', function (Blueprint $table) {
            $table->dropIndex(['ingredient_id']);
        });
    }
};
