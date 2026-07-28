<?php

namespace Database\Factories;

use App\Models\Ingredient;
use App\Models\StockLot;
use App\Models\Workspace;
use App\StockLotOrigin;
use App\StockLotStatus;
use App\StockUnitKind;
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
            'user_packaging_item_id' => null,
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
            'currency' => null,
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
            'user_packaging_item_id' => UserPackagingItemFactory::new(),
            'unit_kind' => StockUnitKind::Count,
        ]);
    }
}
