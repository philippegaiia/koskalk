<?php

namespace Database\Factories;

use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\ProductionRequirement;
use App\Models\ProductionRun;
use App\ProductionRequirementKind;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionRequirement>
 */
class ProductionRequirementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'production_run_id' => ProductionRun::factory(),
            'ingredient_id' => Ingredient::factory(),
            'packaging_item_id' => null,
            'recipe_item_id' => null,
            'recipe_version_packaging_item_id' => null,
            'kind' => ProductionRequirementKind::Ingredient,
            'required_mass_grams' => '100.000000000',
            'required_units' => null,
            'subject_name_snapshot' => fake()->words(2, true),
            'phase_key_snapshot' => 'main',
            'phase_name_snapshot' => 'Main',
            'percentage_snapshot' => '10.000000000',
            'components_per_unit_snapshot' => null,
            'unit_snapshot' => 'g',
            'note_snapshot' => null,
            'sort_order' => 1,
        ];
    }

    public function forPackaging(?PackagingItem $packagingItem = null): static
    {
        return $this->state(fn (): array => [
            'ingredient_id' => null,
            'packaging_item_id' => $packagingItem?->getKey() ?? function (array $attributes): int {
                $productionRun = ProductionRun::query()->findOrFail($attributes['production_run_id']);

                return PackagingItem::factory()
                    ->for($productionRun->workspace)
                    ->create()
                    ->id;
            },
            'recipe_item_id' => null,
            'recipe_version_packaging_item_id' => null,
            'kind' => ProductionRequirementKind::Packaging,
            'required_mass_grams' => null,
            'required_units' => 10,
            'phase_key_snapshot' => null,
            'phase_name_snapshot' => null,
            'percentage_snapshot' => null,
            'components_per_unit_snapshot' => '1.000000000',
            'unit_snapshot' => 'unit',
        ]);
    }
}
