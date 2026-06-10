<?php

namespace Database\Factories;

use App\Models\ProductionBatch;
use App\Models\ProductionBatchPackagingItem;
use App\Models\User;
use App\Models\UserPackagingItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionBatchPackagingItem>
 */
class ProductionBatchPackagingItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'production_batch_id' => ProductionBatch::factory(),
            'user_packaging_item_id' => fn (): int => UserPackagingItem::query()->create([
                'user_id' => User::factory()->create()->id,
                'name' => 'Soap box',
                'unit_cost' => 0.25,
                'currency' => 'EUR',
                'notes' => null,
            ])->id,
            'position' => 1,
            'name' => 'Soap box',
            'components_per_unit' => 1,
            'unit_cost' => 0.25,
            'cost_per_finished_unit' => 0.25,
            'line_cost' => 3,
        ];
    }
}
