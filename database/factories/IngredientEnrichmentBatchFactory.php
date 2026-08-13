<?php

namespace Database\Factories;

use App\Enums\IngredientEnrichmentBatchStatus;
use App\Models\IngredientEnrichmentBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IngredientEnrichmentBatch> */
class IngredientEnrichmentBatchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'requested_by_user_id' => User::factory(),
            'status' => IngredientEnrichmentBatchStatus::Pending,
            'model' => 'gpt-5.6-terra',
            'reasoning_effort' => 'low',
            'prompt_version' => 'ingredient-enrichment-research-v1',
            'schema_version' => 1,
            'mode' => 'fill_missing',
        ];
    }
}
