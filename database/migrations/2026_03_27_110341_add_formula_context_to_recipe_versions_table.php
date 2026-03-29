<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('recipe_versions', function (Blueprint $table) {
            $table->string('manufacturing_mode')->default('saponify_in_formula')->after('batch_unit');
            $table->string('exposure_mode')->default('rinse_off')->after('manufacturing_mode');
            $table->string('regulatory_regime')->default('eu')->after('exposure_mode');
            $table->timestamp('catalog_reviewed_at')->nullable()->after('saved_at');
        });

        DB::table('recipe_versions')
            ->update([
                'manufacturing_mode' => 'saponify_in_formula',
                'exposure_mode' => 'rinse_off',
                'regulatory_regime' => 'eu',
                'catalog_reviewed_at' => now(),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recipe_versions', function (Blueprint $table) {
            $table->dropColumn([
                'manufacturing_mode',
                'exposure_mode',
                'regulatory_regime',
                'catalog_reviewed_at',
            ]);
        });
    }
};
