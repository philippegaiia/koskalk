<?php

namespace Database\Seeders;

use App\Models\Ingredient;
use App\Services\IngredientCatalogConsolidationService;
use App\Services\IngredientFunctionAssignmentService;
use App\Support\CosIngFunctionDataset;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class IngredientCatalogMetadataSeeder extends Seeder
{
    public function __construct(
        private readonly IngredientCatalogConsolidationService $consolidation,
        private readonly CosIngFunctionDataset $cosIngDataset,
        private readonly IngredientFunctionAssignmentService $functionAssignments,
    ) {}

    public function run(): void
    {
        $this->call(IngredientFunctionSeeder::class);
        $this->consolidation->applyMetadata();

        $assignments = $this->cosIngDataset->validateAgainstCatalog($this->cosIngDataset->all());

        foreach ($assignments as $assignment) {
            $ingredient = Ingredient::query()
                ->whereNull('owner_type')
                ->where('catalog_key', $assignment['catalog_key'])
                ->first();

            /** @var Ingredient $ingredient */
            $this->functionAssignments->syncCosIng(
                ingredient: $ingredient,
                functionKeys: $assignment['function_keys'],
                sourceReference: $assignment['source_url'].'#'.$assignment['cosing_reference'],
                checkedAt: CarbonImmutable::createFromFormat('!Y-m-d', $assignment['verified_at']),
            );

            $ingredient->forceFill([
                'cosing_reference' => $assignment['cosing_reference'],
                'taxonomy_reviewed_at' => $assignment['verified_at'],
            ])->save();
        }
    }
}
