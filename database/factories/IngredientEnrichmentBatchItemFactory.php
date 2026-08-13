<?php

namespace Database\Factories;

use App\Enums\IngredientEnrichmentItemStatus;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatch;
use App\Models\IngredientEnrichmentBatchItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IngredientEnrichmentBatchItem> */
class IngredientEnrichmentBatchItemFactory extends Factory
{
    public function definition(): array
    {
        $catalogKey = 'enrichment-'.fake()->unique()->slug(2);

        return [
            'ingredient_enrichment_batch_id' => IngredientEnrichmentBatch::factory(),
            'ingredient_id' => Ingredient::factory()->state(['catalog_key' => $catalogKey]),
            'catalog_key' => $catalogKey,
            'status' => IngredientEnrichmentItemStatus::Pending,
            'snapshot' => ['catalog_key' => $catalogKey],
            'source_fingerprint' => hash('sha256', $catalogKey),
            'warnings' => [],
            'unresolved_questions' => [],
            'sources' => [],
        ];
    }
}
