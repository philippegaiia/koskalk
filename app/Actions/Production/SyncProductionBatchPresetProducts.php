<?php

namespace App\Actions\Production;

use App\Models\ProductionBatchPreset;
use App\Models\Recipe;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SyncProductionBatchPresetProducts
{
    public function __construct(private readonly ProductionBenchAccess $access) {}

    /**
     * @param  array<int, array{recipe_id: int|string, is_default?: bool}>  $assignments
     */
    public function handle(
        User $actor,
        Workspace $workspace,
        ProductionBatchPreset $preset,
        array $assignments,
    ): ProductionBatchPreset {
        $this->access->assertWritable($actor, $workspace);

        $normalizedAssignments = collect($assignments)
            ->map(fn (array $assignment): array => [
                'recipe_id' => (int) ($assignment['recipe_id'] ?? 0),
                'is_default' => (bool) ($assignment['is_default'] ?? false),
            ])
            ->filter(fn (array $assignment): bool => $assignment['recipe_id'] > 0)
            ->keyBy('recipe_id')
            ->values()
            ->all();

        return DB::transaction(function () use ($actor, $normalizedAssignments, $preset, $workspace): ProductionBatchPreset {
            $lockedWorkspace = Workspace::withoutGlobalScopes()
                ->lockForUpdate()
                ->findOrFail($workspace->id);
            $this->access->assertWritable($actor, $lockedWorkspace);

            $lockedPreset = ProductionBatchPreset::query()
                ->lockForUpdate()
                ->find($preset->id);

            if (
                ! $lockedPreset instanceof ProductionBatchPreset
                || (int) $lockedPreset->workspace_id !== (int) $lockedWorkspace->id
            ) {
                throw ValidationException::withMessages([
                    'preset' => 'The batch size does not belong to this workspace.',
                ]);
            }

            $recipeIds = collect($normalizedAssignments)->pluck('recipe_id')->all();
            $recipes = Recipe::withoutGlobalScopes()
                ->where('workspace_id', $lockedWorkspace->id)
                ->whereIn('id', $recipeIds)
                ->lockForUpdate()
                ->get();

            if ($recipes->count() !== count($recipeIds)) {
                throw ValidationException::withMessages([
                    'recipes' => 'Every applicable product must belong to the production workspace.',
                ]);
            }

            foreach ($normalizedAssignments as $assignment) {
                if (! $assignment['is_default'] || ! $lockedPreset->is_active) {
                    continue;
                }

                DB::table('production_batch_preset_recipe')
                    ->where('recipe_id', $assignment['recipe_id'])
                    ->where('is_default', true)
                    ->where('production_batch_preset_id', '!=', $lockedPreset->id)
                    ->update(['is_default' => false, 'updated_at' => now()]);
            }

            $links = collect($normalizedAssignments)
                ->mapWithKeys(fn (array $assignment): array => [
                    $assignment['recipe_id'] => [
                        'is_default' => $lockedPreset->is_active && $assignment['is_default'],
                    ],
                ])
                ->all();

            $lockedPreset->recipes()->sync($links);

            return $lockedPreset->fresh(['recipes', 'defaultRecipes']);
        }, attempts: 5);
    }
}
