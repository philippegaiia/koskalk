<?php

namespace App\Actions\Production;

use App\Enums\ProductionRunStatus;
use App\Enums\StockLotOrigin;
use App\Enums\StockLotStatus;
use App\Models\ProductionRun;
use App\Models\ProductionTask;
use App\Models\StockLot;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Production\ProductionEditingContext;
use App\Services\Production\ProductionMutationResult;
use App\Services\ProductionBenchAccess;
use App\Services\ProductionMutationGuard;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ReleaseOutputLot
{
    public function __construct(private readonly ProductionMutationGuard $guard,
        private readonly ProductionBenchAccess $access,
    ) {}

    /**
     * Release a quarantined output lot after all linked production tasks have
     * been completed. An early release requires an explicit confirmation.
     */
    public function handle(
        User $actor,
        StockLot $lot,
        ?string $note = null,
        bool $earlyReleaseConfirmed = false,
        ?ProductionEditingContext $editing = null,
    ): StockLot {
        $workspace = $lot->workspace;
        if (! $workspace instanceof Workspace) {
            throw ValidationException::withMessages([
                'lot' => __('production_bench.production.validation.output_lot_workspace_missing'),
            ]);
        }
        $this->access->assertWritable($actor, $workspace);
        $lotReference = StockLot::withoutGlobalScopes()->where('workspace_id', $workspace->id)->findOrFail($lot->id);
        $parentId = $lotReference->production_run_id;
        if ($parentId === null) {
            throw ValidationException::withMessages([
                'lot' => __('production_bench.production.validation.output_lot_unlinked'),
            ]);
        }
        if ($parentId !== $lot->production_run_id
            || ! ProductionRun::query()->where('workspace_id', $workspace->id)->whereKey($parentId)->exists()) {
            throw ValidationException::withMessages([
                'lot' => __('production_bench.production.validation.output_production_missing'),
            ]);
        }

        return $this->guard->run($actor, [$parentId], $editing, function (User $actor, Workspace $workspace, Collection $productions) use ($earlyReleaseConfirmed, $lot, $note, $parentId): ProductionMutationResult {
            $production = $productions[$parentId];
            $tasks = ProductionTask::query()
                ->where('production_run_id', $production->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $lockedLot = StockLot::withoutGlobalScopes()->where('workspace_id', $workspace->id)->lockForUpdate()->findOrFail($lot->id);
            if ($lockedLot->production_run_id !== $parentId) {
                throw ValidationException::withMessages([
                    'lot' => __('production_bench.production.validation.output_production_missing'),
                ]);
            }

            if ($production->status !== ProductionRunStatus::Completed) {
                throw ValidationException::withMessages([
                    'lot' => __('production_bench.production.validation.output_release_requires_completed'),
                ]);
            }

            if ($lockedLot->origin !== StockLotOrigin::ProductionOutput) {
                throw ValidationException::withMessages([
                    'lot' => __('production_bench.production.validation.output_release_requires_production_lot'),
                ]);
            }

            if ($lockedLot->status !== StockLotStatus::Quarantined) {
                throw ValidationException::withMessages([
                    'lot' => __('production_bench.production.validation.output_release_requires_quarantined'),
                ]);
            }

            if ($lockedLot->estimated_ready_on !== null
                && $lockedLot->estimated_ready_on->isFuture()
                && ! $earlyReleaseConfirmed) {
                throw ValidationException::withMessages([
                    'early_release_confirmation' => __('production_bench.production.validation.early_release_confirmation', [
                        'date' => $lockedLot->estimated_ready_on->toDateString(),
                    ]),
                ]);
            }

            $incompleteTasks = $tasks->whereNull('completed_at');

            if ($incompleteTasks->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'lot' => __('production_bench.production.validation.output_release_tasks_incomplete', [
                        'tasks' => $incompleteTasks->pluck('name_snapshot')->implode(', '),
                    ]),
                ]);
            }

            $lockedLot->update([
                'status' => StockLotStatus::Released,
                'released_at' => now(),
                'released_by_user_id' => $actor->id,
                'release_note' => $note !== null && trim($note) !== '' ? trim($note) : null,
            ]);

            return new ProductionMutationResult($lockedLot->refresh(), [$parentId]);
        });
    }
}
