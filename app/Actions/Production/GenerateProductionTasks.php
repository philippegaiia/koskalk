<?php

namespace App\Actions\Production;

use App\Models\ProductionRun;
use App\Models\ProductionTask;
use App\Models\ProductionTaskSet;
use App\Models\Recipe;
use App\Models\User;
use App\Models\Workspace;
use App\ProductionRunStatus;
use App\Services\Production\ProductionWorkingCalendar;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GenerateProductionTasks
{
    public function __construct(
        private readonly ProductionBenchAccess $access,
        private readonly ProductionWorkingCalendar $calendar,
    ) {}

    public function handle(User $actor, ProductionRun $production): ProductionRun
    {
        $workspace = $production->workspace;

        if ($workspace === null) {
            throw ValidationException::withMessages([
                'production' => 'The production workspace could not be found.',
            ]);
        }

        $this->access->assertWritable($actor, $workspace);

        return DB::transaction(function () use ($actor, $production): ProductionRun {
            $lockedProduction = ProductionRun::query()
                ->lockForUpdate()
                ->findOrFail($production->id);
            $lockedWorkspace = Workspace::withoutGlobalScopes()
                ->lockForUpdate()
                ->find($lockedProduction->workspace_id);

            if ($lockedWorkspace === null) {
                throw ValidationException::withMessages([
                    'production' => 'The production workspace could not be found.',
                ]);
            }

            $this->access->assertWritable($actor, $lockedWorkspace);

            if (! in_array($lockedProduction->status, [
                ProductionRunStatus::Draft,
                ProductionRunStatus::Scheduled,
                ProductionRunStatus::Reserved,
            ], true)) {
                throw ValidationException::withMessages([
                    'production' => 'Tasks can only be generated before production starts.',
                ]);
            }

            if (ProductionTask::query()
                ->where('production_run_id', $lockedProduction->id)
                ->exists()) {
                return $lockedProduction->fresh(['requirements', 'tasks']);
            }

            if ($lockedProduction->planned_for === null) {
                return $lockedProduction->fresh(['requirements', 'tasks']);
            }

            $taskSet = $this->resolveTaskSet($lockedProduction, $lockedWorkspace);

            if ($taskSet === null || ! $taskSet->is_active) {
                return $lockedProduction->fresh(['requirements', 'tasks']);
            }

            $items = $taskSet->items()->with('taskType.department')->lockForUpdate()->get();

            if ($items->isEmpty()) {
                return $lockedProduction->fresh(['requirements', 'tasks']);
            }

            if (! $items->contains(fn ($item): bool => (int) $item->days_after_production === 0)) {
                throw ValidationException::withMessages([
                    'production_task_set' => 'The task set must include a production-day task.',
                ]);
            }

            if ((int) $lockedProduction->production_task_set_id !== (int) $taskSet->id) {
                $lockedProduction->update(['production_task_set_id' => $taskSet->id]);
            }

            foreach ($items as $item) {
                if ($item->taskType === null || (int) $item->taskType->workspace_id !== (int) $lockedWorkspace->id) {
                    throw ValidationException::withMessages([
                        'production' => 'Every production task must belong to the active workspace.',
                    ]);
                }

                if ($item->taskType->department_id !== null
                    && ($item->taskType->department === null
                        || (int) $item->taskType->department->workspace_id !== (int) $lockedWorkspace->id)) {
                    throw ValidationException::withMessages([
                        'production' => 'Every production task department must belong to the active workspace.',
                    ]);
                }

                $scheduledFor = $this->calendar->dateRelativeToProduction(
                    $lockedWorkspace,
                    $lockedProduction->planned_for,
                    (int) $item->days_after_production,
                )->toDateString();

                $lockedProduction->tasks()->create([
                    'workspace_id' => $lockedWorkspace->id,
                    'production_task_set_id' => $taskSet->id,
                    'production_task_set_item_id' => $item->id,
                    'name_snapshot' => $item->taskType->name,
                    'colour_snapshot' => $item->taskType->colour,
                    'department_id' => $item->taskType->department?->is_active === true
                        ? $item->taskType->department_id
                        : null,
                    'days_after_production' => $item->days_after_production,
                    'duration_minutes' => $item->duration_minutes ?? $item->taskType->default_duration_minutes,
                    'scheduled_for' => $scheduledFor,
                    'scheduling_mode' => 'automatic',
                ]);
            }

            return $lockedProduction->fresh(['requirements', 'tasks']);
        }, attempts: 5);
    }

    private function resolveTaskSet(ProductionRun $production, Workspace $workspace): ?ProductionTaskSet
    {
        if ($production->production_task_set_id !== null) {
            $taskSet = ProductionTaskSet::query()
                ->where('workspace_id', $workspace->id)
                ->lockForUpdate()
                ->find($production->production_task_set_id);

            if ($taskSet === null) {
                throw ValidationException::withMessages([
                    'production_task_set' => 'The production task set is not available in this workspace.',
                ]);
            }

            return $taskSet;
        }

        $recipe = Recipe::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->lockForUpdate()
            ->find($production->recipe_id);

        if ($recipe === null) {
            return null;
        }

        $taskSet = $recipe->defaultProductionTaskSets()
            ->where('workspace_id', $workspace->id)
            ->lockForUpdate()
            ->first();

        return $taskSet instanceof ProductionTaskSet ? $taskSet : null;
    }
}
