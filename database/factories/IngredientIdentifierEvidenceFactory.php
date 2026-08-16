<?php

namespace Database\Factories;

use App\Enums\IngredientEvidenceConfidence;
use App\Enums\IngredientSourceTier;
use App\Models\IngredientIdentifier;
use App\Models\IngredientIdentifierEvidence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IngredientIdentifierEvidence>
 */
class IngredientIdentifierEvidenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ingredient_identifier_id' => IngredientIdentifier::factory(),
            'source_name' => 'European Commission',
            'source_url' => 'https://single-market-economy.ec.europa.eu/sectors/cosmetics/cosmetic-ingredient-database/cosing-glossary-ingredients_en',
            'source_tier' => IngredientSourceTier::Official,
            'confidence' => IngredientEvidenceConfidence::Verified,
            'source_version' => '32025D1175',
            'source_updated_at' => now()->toDateString(),
            'retrieved_at' => now(),
        ];
    }
}
