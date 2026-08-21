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
        foreach (['product_areas', 'product_categories', 'product_types', 'ifra_product_categories'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->json('translations')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['product_areas', 'product_categories', 'product_types', 'ifra_product_categories'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('translations');
            });
        }
    }
};
