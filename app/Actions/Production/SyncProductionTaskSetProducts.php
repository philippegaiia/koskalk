<?php

namespace App\Actions\Production;

use App\Models\ProductionTaskSet;
use App\Models\Recipe;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SyncProductionTaskSetProducts
{
    public function __construct(private readonly ProductionBenchAccess $access) {}

    /**
     * @param  array<int, int|string>  $recipeIds
     */
    public function handle(
        User $actor,
        Workspace $workspace,
        ProductionTaskSet $taskSet,
        array $recipeIds = [],
        ?int $defaultRecipeId = null,
        ?array $assignments = null,
    ): ProductionTaskSet {
        $this->access->assertWritable($actor, $workspace);

        $assignments ??= collect($recipeIds)
            ->map(fn (int|string $recipeId): int => (int) $recipeId)
            ->filter(fn (int $recipeId): bool => $recipeId > 0)
            ->unique()
            ->mapWithKeys(fn (int $recipeId): array => [
                $recipeId => ['recipe_id' => $recipeId, 'is_default' => $recipeId === $defaultRecipeId],
            ])
            ->values()
            ->all();

        $normalizedAssignments = collect($assignments)
            ->map(function (array $assignment): array {
                return [
                    'recipe_id' => (int) ($assignment['recipe_id'] ?? 0),
                    'is_default' => (bool) ($assignment['is_default'] ?? false),
                ];
            })
            ->filter(fn (array $assignment): bool => $assignment['recipe_id'] > 0)
            ->keyBy('recipe_id')
            ->values()
            ->all();

        $recipeIds = collect($normalizedAssignments)->pluck('recipe_id')->all();

        if ($defaultRecipeId !== null && ! in_array($defaultRecipeId, $recipeIds, true)) {
            throw ValidationException::withMessages([
                'default_recipe' => 'The default product must be selected as an applicable product.',
            ]);
        }

        return DB::transaction(function () use ($actor, $normalizedAssignments, $recipeIds, $taskSet, $workspace): ProductionTaskSet {
            $lockedWorkspace = Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($workspace->id);
            $this->access->assertWritable($actor, $lockedWorkspace);

            $lockedTaskSet = ProductionTaskSet::query()
                ->lockForUpdate()
                ->find($taskSet->id);

            if (! $lockedTaskSet instanceof ProductionTaskSet || (int) $lockedTaskSet->workspace_id !== (int) $lockedWorkspace->id) {
                throw ValidationException::withMessages([
                    'task_set' => 'The task set does not belong to this workspace.',
                ]);
            }

            $recipes = Recipe::withoutGlobalScopes()
                ->where('workspace_id', $lockedWorkspace->id)
                ->whereNull('archived_at')
                ->whereIn('id', $recipeIds)
                ->lockForUpdate()
                ->get();

            if ($recipes->count() !== count($recipeIds)) {
                throw ValidationException::withMessages([
                    'recipes' => 'Every applicable product must belong to the production workspace.',
                ]);
            }

            foreach ($normalizedAssignments as $assignment) {
                if (! $assignment['is_default'] || ! $lockedTaskSet->is_active) {
                    continue;
                }

                DB::table('production_task_set_recipe')
                    ->where('recipe_id', $assignment['recipe_id'])
                    ->where('is_default', true)
                    ->where('production_task_set_id', '!=', $lockedTaskSet->id)
                    ->update(['is_default' => false, 'updated_at' => now()]);
            }

            $links = collect($normalizedAssignments)
                ->mapWithKeys(fn (array $assignment): array => [
                    $assignment['recipe_id'] => [
                        'is_default' => $lockedTaskSet->is_active && $assignment['is_default'],
                    ],
                ])
                ->all();

            $lockedTaskSet->recipes()->sync($links);

            return $lockedTaskSet->fresh(['recipes', 'defaultRecipes', 'items.taskType']);
        }, attempts: 5);
    }
}
