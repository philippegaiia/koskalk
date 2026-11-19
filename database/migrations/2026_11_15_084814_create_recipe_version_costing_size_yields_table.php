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
        Schema::create('recipe_version_costing_size_yields', function (Blueprint $table) {
            $table->foreignId('recipe_version_costing_id')->constrained(indexName: 'costing_size_yields_costing_foreign')->cascadeOnDelete();
            $table->foreignId('product_size_id')->index('costing_size_yields_size_index')->constrained(indexName: 'costing_size_yields_size_foreign')->cascadeOnDelete();
            $table->unsignedInteger('units_produced')->nullable();
            $table->primary(['recipe_version_costing_id', 'product_size_id'], 'costing_size_yields_primary');
        });

        DB::table('recipe_version_costings as costing')
            ->join('recipe_versions as version', 'version.id', '=', 'costing.recipe_version_id')
            ->where('costing.units_produced', '>', 0)
            ->select(['costing.id', 'costing.units_produced', 'version.recipe_id', 'costing.recipe_version_id'])
            ->chunkById(200, function ($costings): void {
                foreach ($costings as $costing) {
                    $sizes = DB::table('product_sizes')->where('recipe_id', $costing->recipe_id)
                        ->orderBy('position')->orderBy('id');
                    $sizeId = (clone $sizes)->whereIn('id', DB::table('recipe_version_packaging_items')
                        ->where('recipe_version_id', $costing->recipe_version_id)->select('product_size_id'))->value('id')
                        ?? $sizes->whereNull('archived_at')->value('id');

                    if ($sizeId !== null) {
                        DB::table('recipe_version_costing_size_yields')->insert([
                            'recipe_version_costing_id' => $costing->id,
                            'product_size_id' => $sizeId,
                            'units_produced' => $costing->units_produced,
                        ]);
                    }
                }
            }, 'costing.id', 'id');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recipe_version_costing_size_yields');
    }
};
