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
        Schema::table('recipe_versions', function (Blueprint $table) {
            $table->foreignId('ifra_product_category_id')
                ->nullable()
                ->after('batch_unit')
                ->constrained()
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recipe_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ifra_product_category_id');
        });
    }
};
