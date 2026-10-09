<?php

namespace App\Actions\Production;

use App\Enums\ProductionRunStatus;
use App\Models\ProductionRun;
use App\Models\ProductionTask;
use App\Models\ProductionTaskSet;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Production\ProductionEditingContext;
use App\Services\Production\ProductionMutationResult;
use App\Services\Production\ProductionMutationScope;
use App\Services\Production\ProductionTaskLimits;
use App\Services\Production\ProductionWorkingCalendar;
use App\Services\ProductionBenchAccess;
use App\Services\ProductionMutationGuard;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class GenerateProductionTasks
{
    public function __construct(private readonly ProductionMutationGuard $guard,
        private readonly ProductionBenchAccess $access,
        private readonly ProductionWorkingCalendar $calendar,
        private readonly ProductionTaskLimits $limits,
    ) {}

    public function handle(User $actor, ProductionRun $production,
        ?ProductionEditingContext $editing = null,
    ): ProductionRun {
        $workspace = $production->workspace;

        if ($workspace === null) {
            throw ValidationException::withMessages([
                'production' => __('production_bench.production.workspace_missing'),
            ]);
        }

        $this->access->assertWritable($actor, $workspace);

        return $this->guard->run($actor, [$production->id], $editing, function (User $actor, Workspace $lockedWorkspace, Collection $productions, ProductionMutationScope $scope) use ($production): ProductionMutationResult {
            $lockedProduction = $productions[$production->id];

            if ($lockedWorkspace === null) {
                throw ValidationException::withMessages([
                    'production' => __('production_bench.production.workspace_missing'),
                ]);
            }

            $this->access->assertWritable($actor, $lockedWorkspace);

            $before = $lockedProduction->tasks()->count();
            $result = $this->generateForLockedProduction($actor, $lockedProduction, $lockedWorkspace, $scope);

            return new ProductionMutationResult($result, $result->tasks->count() !== $before ? [$result->id] : []);
        });
    }

    public function generateForLockedProduction(
        User $actor,
        ProductionRun $lockedProduction,
        Workspace $lockedWorkspace,
        ProductionMutationScope $scope,
    ): ProductionRun {
        $scope->assertFor($actor, $lockedProduction, $lockedWorkspace);
        $this->access->assertWritable($actor, $lockedWorkspace);

        if (! in_array($lockedProduction->status, [
            ProductionRunStatus::Draft,
            ProductionRunStatus::Scheduled,
            ProductionRunStatus::Reserved,
        ], true)) {
            throw ValidationException::withMessages([
                'production' => __('production_bench.production.validation.tasks_before_start'),
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

        $this->limits->assertUsableTaskSet($taskSet);
        $items = $taskSet->items()->with('taskType.department')->lockForUpdate()->limit(ProductionTaskLimits::MAX_ITEMS_PER_SET + 1)->get();
        $this->limits->assertItemCount($items->count(), 'production_task_set');

        if ($items->isEmpty()) {
            return $lockedProduction->fresh(['requirements', 'tasks']);
        }

        if (! $items->contains(fn ($item): bool => (int) $item->days_after_production === 0)) {
            throw ValidationException::withMessages([
                'production_task_set' => __('production_bench.settings.task_set_production_day_required'),
            ]);
        }

        if ((int) $lockedProduction->production_task_set_id !== (int) $taskSet->id) {
            $lockedProduction->update(['production_task_set_id' => $taskSet->id]);
        }

        foreach ($items as $item) {
            if ($item->taskType === null || (int) $item->taskType->workspace_id !== (int) $lockedWorkspace->id) {
                throw ValidationException::withMessages([
                    'production' => __('production_bench.production.validation.task_workspace_invalid'),
                ]);
            }

            if ($item->taskType->department_id !== null
                && ($item->taskType->department === null
                    || (int) $item->taskType->department->workspace_id !== (int) $lockedWorkspace->id)) {
                throw ValidationException::withMessages([
                    'production' => __('production_bench.production.validation.task_department_workspace_invalid'),
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
    }

    private function resolveTaskSet(ProductionRun $production, Workspace $workspace): ?ProductionTaskSet
    {
        if ($production->production_task_set_id === null) {
            return null;
        }

        $taskSet = ProductionTaskSet::query()
            ->where('workspace_id', $workspace->id)
            ->lockForUpdate()
            ->find($production->production_task_set_id);

        if ($taskSet === null) {
            throw ValidationException::withMessages([
                'production_task_set' => __('production_bench.production.validation.task_set_unavailable'),
            ]);
        }

        return $taskSet;
    }
}
