<?php

namespace App\Actions\Production;

use App\Models\ProductionRequirement;
use App\Models\ProductionRun;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Services\Production\ProductionFormulaSnapshotBuilder;
use Illuminate\Support\Facades\DB;

class BackfillProductionFormulaSnapshot
{
    public function __construct(
        private readonly ProductionFormulaSnapshotBuilder $formulaSnapshotBuilder,
    ) {}

    /**
     * Backfill one production's independent formula snapshot from its still
     * present source recipe and version. Idempotent: a run with a completed
     * snapshot is returned untouched.
     *
     * @return bool true when the snapshot is complete after this call
     */
    public function handle(ProductionRun $production): bool
    {
        return DB::transaction(function () use ($production): bool {
            $lockedProduction = ProductionRun::query()
                ->lockForUpdate()
                ->findOrFail($production->id);

            if ($lockedProduction->formula_snapshot_completed_at !== null) {
                return true;
            }

            $recipe = Recipe::withoutGlobalScopes()->find($lockedProduction->recipe_id);
            $version = RecipeVersion::withoutGlobalScopes()->find($lockedProduction->recipe_version_id);

            if (! $recipe instanceof Recipe || ! $version instanceof RecipeVersion) {
                return false;
            }

            $requirements = ProductionRequirement::query()
                ->where('production_run_id', $lockedProduction->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (ProductionRequirement $requirement): array => [
                    'kind' => $requirement->kind->value,
                    'ingredient_id' => $requirement->ingredient_id,
                    'recipe_item_id' => $requirement->recipe_item_id,
                    'subject_name_snapshot' => $requirement->subject_name_snapshot,
                    'phase_key_snapshot' => $requirement->phase_key_snapshot,
                    'phase_name_snapshot' => $requirement->phase_name_snapshot,
                    'percentage_snapshot' => $requirement->percentage_snapshot,
                    'components_per_unit_snapshot' => $requirement->components_per_unit_snapshot,
                    'required_mass_grams' => $requirement->required_mass_grams,
                    'required_units' => $requirement->required_units,
                    'unit_snapshot' => $requirement->unit_snapshot,
                    'note_snapshot' => $requirement->note_snapshot,
                ])
                ->values();

            $snapshot = $this->formulaSnapshotBuilder->build(
                recipe: $recipe,
                version: $version,
                basisQuantityGrams: (string) $lockedProduction->basis_quantity_grams,
                requirements: $requirements,
            );

            $lockedProduction->update([
                'recipe_name_snapshot' => $recipe->name,
                'source_formula_version_number' => $version->version_number,
                'formula_context_snapshot' => $snapshot['context'],
                'formula_snapshot_completed_at' => now(),
            ]);
            $lockedProduction->formulaLines()->createMany($snapshot['lines']->all());

            return true;
        }, attempts: 3);
    }
}
