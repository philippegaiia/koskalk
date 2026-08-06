<?php

namespace App\Actions\Production;

use App\Models\ProductionTaskSet;
use App\Models\ProductionTaskType;
use App\Models\Recipe;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveProductionTaskSet
{
    public function __construct(
        private readonly ProductionBenchAccess $access,
        private readonly SyncProductionTaskSetProducts $syncProducts,
    ) {}

    /**
     * @param  array<int, array{task_type_id?: int, task_type?: ProductionTaskType, days_after_production?: int, duration_minutes?: int|null}>  $items
     */
    public function handle(
        User $actor,
        Workspace $workspace,
        string $name,
        array $items,
        ?Recipe $recipe = null,
        bool $isDefault = false,
        bool $isActive = true,
        ?ProductionTaskSet $taskSet = null,
    ): ProductionTaskSet {
        $this->access->assertWritable($actor, $workspace);
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 120) {
            throw ValidationException::withMessages(['name' => 'Enter a task set name.']);
        }

        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'Add at least one task to the set.']);
        }

        $normalizedItems = $this->normalizeItems($items);

        return DB::transaction(function () use (
            $actor,
            $isActive,
            $isDefault,
            $name,
            $normalizedItems,
            $recipe,
            $taskSet,
            $workspace,
        ): ProductionTaskSet {
            $lockedWorkspace = Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($workspace->id);
            $this->access->assertWritable($actor, $lockedWorkspace);

            $lockedRecipe = null;

            if ($recipe instanceof Recipe) {
                $lockedRecipe = Recipe::withoutGlobalScopes()->lockForUpdate()->find($recipe->id);

                if (! $lockedRecipe instanceof Recipe || (int) $lockedRecipe->workspace_id !== (int) $lockedWorkspace->id) {
                    throw ValidationException::withMessages([
                        'recipe' => 'The recipe must belong to the production workspace.',
                    ]);
                }
            }

            $current = null;

            if ($taskSet instanceof ProductionTaskSet) {
                $current = ProductionTaskSet::query()->lockForUpdate()->find($taskSet->id);

                if (! $current instanceof ProductionTaskSet || (int) $current->workspace_id !== (int) $lockedWorkspace->id) {
                    throw ValidationException::withMessages([
                        'task_set' => 'The task set does not belong to this workspace.',
                    ]);
                }
            }

            foreach ($normalizedItems as $item) {
                $taskType = ProductionTaskType::query()
                    ->where('workspace_id', $lockedWorkspace->id)
                    ->lockForUpdate()
                    ->find($item['production_task_type_id']);

                if (! $taskType instanceof ProductionTaskType) {
                    throw ValidationException::withMessages([
                        'items' => 'Every task type must belong to the production workspace.',
                    ]);
                }
            }

            $values = [
                'workspace_id' => $lockedWorkspace->id,
                'name' => $name,
                'is_active' => $isActive,
            ];
            $current ??= ProductionTaskSet::query()->create($values);

            if ($current->exists && $current->wasRecentlyCreated === false) {
                $current->update($values);
            }

            $current->items()->delete();
            $current->items()->createMany($normalizedItems);

            if ($lockedRecipe instanceof Recipe) {
                $this->syncProducts->handle(
                    actor: $actor,
                    workspace: $lockedWorkspace,
                    taskSet: $current,
                    recipeIds: [$lockedRecipe->id],
                    defaultRecipeId: $isDefault && $isActive ? $lockedRecipe->id : null,
                );
            }

            return $current->fresh('items.taskType');
        }, attempts: 5);
    }

    /**
     * @param  array<int, array{task_type_id?: int, task_type?: ProductionTaskType, days_after_production?: int, duration_minutes?: int|null}>  $items
     * @return array<int, array{production_task_type_id: int, position: int, days_after_production: int, duration_minutes: int|null}>
     */
    private function normalizeItems(array $items): array
    {
        $normalized = [];

        foreach (array_values($items) as $index => $item) {
            $taskTypeId = $item['task_type_id'] ?? (($item['task_type'] ?? null)?->id ?? null);
            $days = $item['days_after_production'] ?? 0;
            $duration = $item['duration_minutes'] ?? null;

            if (! is_int($taskTypeId) && ! (is_string($taskTypeId) && ctype_digit($taskTypeId))) {
                throw ValidationException::withMessages([
                    "items.{$index}.task_type_id" => 'Choose a task type.',
                ]);
            }

            if (! is_int($days) && ! (is_string($days) && preg_match('/^-?\d+$/', $days) === 1)) {
                throw ValidationException::withMessages([
                    "items.{$index}.days_after_production" => 'The relative production day must be a whole number.',
                ]);
            }

            if ($duration !== null && (! is_int($duration) && ! (is_string($duration) && preg_match('/^\d+$/', $duration) === 1))) {
                throw ValidationException::withMessages([
                    "items.{$index}.duration_minutes" => 'Duration must be a whole number.',
                ]);
            }

            $normalized[] = [
                'production_task_type_id' => (int) $taskTypeId,
                'position' => $index + 1,
                'days_after_production' => (int) $days,
                'duration_minutes' => $duration === null ? null : (int) $duration,
            ];
        }

        if (! collect($normalized)->contains(fn (array $item): bool => $item['days_after_production'] === 0)) {
            throw ValidationException::withMessages([
                'items' => 'Add at least one task scheduled on the production day.',
            ]);
        }

        return $normalized;
    }
}
