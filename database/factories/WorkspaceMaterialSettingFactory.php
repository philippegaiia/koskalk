<?php

namespace Database\Factories;

use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\Workspace;
use App\Models\WorkspaceMaterialSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceMaterialSetting>
 */
class WorkspaceMaterialSettingFactory extends Factory
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
            'buffer_quantity' => '1000.000000000',
        ];
    }

    public function forPackagingItem(PackagingItem $packagingItem): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $packagingItem->workspace_id,
            'ingredient_id' => null,
            'packaging_item_id' => $packagingItem->id,
        ]);
    }
}
