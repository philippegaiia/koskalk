<?php

namespace Database\Factories;

use App\Models\ProductionRun;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\Workspace;
use App\OwnerType;
use App\ProductionBasisKind;
use App\ProductionRunSource;
use App\ProductionRunStatus;
use App\Visibility;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionRun>
 */
class ProductionRunFactory extends Factory
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
            'recipe_id' => function (array $attributes): int {
                $workspace = Workspace::withoutGlobalScopes()->findOrFail($attributes['workspace_id']);

                return Recipe::factory()->create([
                    'owner_type' => OwnerType::Workspace,
                    'owner_id' => $workspace->id,
                    'workspace_id' => $workspace->id,
                    'visibility' => Visibility::Private,
                ])->id;
            },
            'recipe_version_id' => function (array $attributes): int {
                $workspace = Workspace::withoutGlobalScopes()->findOrFail($attributes['workspace_id']);
                $recipe = Recipe::withoutGlobalScopes()->findOrFail($attributes['recipe_id']);

                return RecipeVersion::factory()->for($recipe)->create([
                    'owner_type' => OwnerType::Workspace,
                    'owner_id' => $workspace->id,
                    'workspace_id' => $workspace->id,
                    'visibility' => Visibility::Private,
                    'is_current' => false,
                ])->id;
            },
            'status' => ProductionRunStatus::Draft,
            'source' => ProductionRunSource::Direct,
            'planned_for' => today()->addWeek(),
            'basis_kind' => ProductionBasisKind::OilMass,
            'basis_quantity_grams' => '1000.000000000',
            'basis_input_value' => '1.000000000',
            'basis_input_unit' => 'kg',
            'expected_units' => 10,
            'notes' => null,
            'idempotency_key' => fake()->uuid(),
            'created_by_user_id' => fn (array $attributes): int => Workspace::withoutGlobalScopes()
                ->findOrFail($attributes['workspace_id'])
                ->owner_user_id,
            'cancelled_at' => null,
            'cancelled_by_user_id' => null,
            'cancellation_reason' => null,
        ];
    }
}
