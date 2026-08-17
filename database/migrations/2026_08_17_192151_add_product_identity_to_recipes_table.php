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
        Schema::table('recipes', function (Blueprint $table) {
            $table->string('product_reference', 100)->nullable();
            $table->decimal('nominal_content_value', 12, 4)->nullable();
            $table->string('nominal_content_unit', 16)->nullable();
            $table->unique(
                ['workspace_id', 'product_reference'],
                'recipes_workspace_product_reference_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->dropUnique('recipes_workspace_product_reference_unique');
            $table->dropColumn([
                'product_reference',
                'nominal_content_value',
                'nominal_content_unit',
            ]);
        });
    }
};
