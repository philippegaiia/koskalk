<?php

namespace Database\Factories;

use App\Enums\OwnerType;
use App\Enums\ProductionBasisKind;
use App\Enums\ProductionRunSource;
use App\Enums\ProductionRunStatus;
use App\Enums\Visibility;
use App\Models\ProductionRun;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\Workspace;
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
            'recipe_name_snapshot' => null,
            'source_formula_version_number' => null,
            'formula_context_snapshot' => null,
            'formula_snapshot_completed_at' => null,
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
            'planning_batch_number' => 'T'.str_pad((string) fake()->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'batch_number' => null,
            'batch_number_serial' => null,
            'batch_number_assigned_at' => null,
            'batch_number_assigned_by_user_id' => null,
            'started_at' => null,
            'started_by_user_id' => null,
            'completed_at' => null,
            'completed_by_user_id' => null,
            'aborted_at' => null,
            'aborted_by_user_id' => null,
            'abort_reason' => null,
            'manufacture_date' => null,
            'actual_output_units' => null,
            'actual_output_mass_grams' => null,
            'cost_currency' => null,
            'actual_ingredient_total' => null,
            'actual_packaging_total' => null,
            'actual_total_cost' => null,
            'actual_cost_per_unit' => null,
            'cancelled_at' => null,
            'cancelled_by_user_id' => null,
            'cancellation_reason' => null,
        ];
    }
}
