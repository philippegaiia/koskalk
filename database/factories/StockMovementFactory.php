<?php

namespace Database\Factories;

use App\Enums\StockMovementType;
use App\Models\StockLot;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stock_lot_id' => StockLot::factory(),
            'workspace_id' => fn (array $attributes): int => StockLot::query()
                ->findOrFail($attributes['stock_lot_id'])
                ->workspace_id,
            'type' => StockMovementType::OpeningBalance,
            'quantity_delta' => '1000.000000000',
            'original_quantity' => '1.000000000',
            'original_unit' => 'kg',
            'occurred_at' => now(),
            'actor_user_id' => null,
            'source_type' => null,
            'source_id' => null,
            'reversal_of_stock_movement_id' => null,
            'idempotency_key' => (string) Str::uuid(),
            'note' => null,
        ];
    }
}
