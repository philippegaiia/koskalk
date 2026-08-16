<?php

namespace Database\Factories;

use App\Enums\IngredientEvidenceConfidence;
use App\Enums\IngredientLabelMarket;
use App\Enums\IngredientSourceTier;
use App\Models\Ingredient;
use App\Models\IngredientMarketLabel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IngredientMarketLabel>
 */
class IngredientMarketLabelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ingredient_id' => Ingredient::factory(),
            'market_code' => IngredientLabelMarket::Eu,
            'declaration_name' => 'CI 77491',
            'source_name' => 'European Commission',
            'source_url' => 'https://single-market-economy.ec.europa.eu/sectors/cosmetics/cosmetic-ingredient-database/cosing-glossary-ingredients_en',
            'source_tier' => IngredientSourceTier::Official,
            'confidence' => IngredientEvidenceConfidence::Verified,
            'source_version' => '32025D1175',
            'source_updated_at' => now()->toDateString(),
            'retrieved_at' => now(),
            'effective_from' => null,
            'effective_until' => null,
            'reviewed_at' => now(),
            'reviewed_by_user_id' => null,
        ];
    }
}
