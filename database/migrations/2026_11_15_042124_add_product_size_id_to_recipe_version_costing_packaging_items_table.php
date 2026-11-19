<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record which Product size each packaging price belongs to, so two sizes
     * that use the same item keep their own prices. Existing prices belong to
     * the Product's first size, the only size any Product had until now.
     */
    public function up(): void
    {
        Schema::table('recipe_version_costing_packaging_items', function (Blueprint $table): void {
            $table->foreignId('product_size_id')->nullable()->after('recipe_version_costing_id')->index()->constrained()->nullOnDelete();
        });

        DB::table('recipes')
            ->whereIn('id', DB::table('product_sizes')->select('recipe_id'))
            ->select('id')
            ->chunkById(200, function ($recipes): void {
                foreach ($recipes as $recipe) {
                    $productSizeId = DB::table('product_sizes')
                        ->where('recipe_id', $recipe->id)
                        ->whereNull('archived_at')
                        ->orderBy('position')
                        ->orderBy('id')
                        ->value('id');

                    if ($productSizeId === null) {
                        continue;
                    }

                    DB::table('recipe_version_costing_packaging_items')
                        ->whereNull('product_size_id')
                        ->whereIn('recipe_version_costing_id', DB::table('recipe_version_costings')
                            ->join('recipe_versions', 'recipe_versions.id', '=', 'recipe_version_costings.recipe_version_id')
                            ->where('recipe_versions.recipe_id', $recipe->id)
                            ->select('recipe_version_costings.id'))
                        ->update(['product_size_id' => $productSizeId]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recipe_version_costing_packaging_items', function (Blueprint $table): void {
            $table->dropForeign(['product_size_id']);
        });

        Schema::table('recipe_version_costing_packaging_items', function (Blueprint $table): void {
            $table->dropIndex(['product_size_id']);
        });

        Schema::table('recipe_version_costing_packaging_items', function (Blueprint $table): void {
            $table->dropColumn('product_size_id');
        });
    }
};
