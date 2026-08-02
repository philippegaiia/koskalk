<?php

namespace Database\Factories;

use App\Models\PackagingItem;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchPackagingItem;
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
            'packaging_item_id' => fn (): int => PackagingItem::factory()->create([
                'name' => 'Soap box',
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
