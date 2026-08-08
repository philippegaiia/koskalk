<?php

namespace Database\Factories;

use App\Enums\StockLotOrigin;
use App\Enums\StockLotStatus;
use App\Enums\StockUnitKind;
use App\Models\Ingredient;
use App\Models\StockLot;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockLot>
 */
class StockLotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'ingredient_id' => Ingredient::factory(),
            'packaging_item_id' => null,
            'internal_lot_code' => 'SK-'.fake()->unique()->numerify('######'),
            'supplier_batch_number' => null,
            'origin' => StockLotOrigin::OpeningBalance,
            'unit_kind' => StockUnitKind::Mass,
            'status' => StockLotStatus::Quarantined,
            'stocked_at' => now()->toDateString(),
            'expires_at' => null,
            'available_from' => null,
            'released_at' => null,
            'released_by_user_id' => null,
            'release_note' => null,
            'provenance_complete' => false,
            'historical_unit_cost' => null,
            'costing_unit_cost' => null,
            'currency' => null,
            'costing_currency' => null,
            'exchange_rate' => null,
            'exchange_rate_date' => null,
            'exchange_rate_provider' => null,
            'exchange_rate_is_manual' => false,
            'notes' => null,
        ];
    }

    public function released(): static
    {
        return $this->state(fn (): array => [
            'status' => StockLotStatus::Released,
            'released_at' => now(),
        ]);
    }

    public function forPackaging(): static
    {
        return $this->state(fn (): array => [
            'ingredient_id' => null,
            'packaging_item_id' => PackagingItemFactory::new(),
            'recipe_id' => null,
            'unit_kind' => StockUnitKind::Count,
        ]);
    }

    public function forRecipe(): static
    {
        return $this->state(fn (): array => [
            'ingredient_id' => null,
            'packaging_item_id' => null,
            'recipe_id' => RecipeFactory::new(),
            'unit_kind' => StockUnitKind::Count,
        ]);
    }
}
