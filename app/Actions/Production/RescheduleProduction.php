<?php

namespace App\Actions\Production;

use App\Enums\ProductionRunStatus;
use App\Models\ProductionRun;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Production\ProductionDateRescheduler;
use App\Services\Production\ProductionEditingContext;
use App\Services\Production\ProductionMutationResult;
use App\Services\Production\ProductionMutationScope;
use App\Services\ProductionBenchAccess;
use App\Services\ProductionMutationGuard;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class RescheduleProduction
{
    public function __construct(private readonly ProductionMutationGuard $guard,
        private readonly ProductionBenchAccess $access,
        private readonly ProductionDateRescheduler $rescheduler,
    ) {}

    public function handle(User $actor, ProductionRun $production, string $plannedFor,
        ?ProductionEditingContext $editing = null,
    ): ProductionRun {
        $this->validateDate($plannedFor);
        $workspace = $production->workspace;

        if ($workspace === null) {
            throw ValidationException::withMessages(['production' => __('production_bench.production.workspace_missing')]);
        }

        $this->access->assertWritable($actor, $workspace);

        return $this->guard->run($actor, [$production->id], $editing, function (User $actor, Workspace $lockedWorkspace, Collection $productions, ProductionMutationScope $scope) use ($plannedFor, $production): ProductionMutationResult {

            if ($lockedWorkspace === null) {
                throw ValidationException::withMessages(['production' => __('production_bench.production.workspace_missing')]);
            }

            $this->access->assertWritable($actor, $lockedWorkspace);
            $lockedProduction = $productions[$production->id];

            if (! in_array($lockedProduction->status, [
                ProductionRunStatus::Draft,
                ProductionRunStatus::Scheduled,
                ProductionRunStatus::Reserved,
            ], true)) {
                throw ValidationException::withMessages([
                    'production' => __('production_bench.production.validation.reschedule_after_start'),
                ]);
            }

            $changed = $lockedProduction->planned_for?->toDateString() !== $plannedFor;
            if (! $changed) {
                return new ProductionMutationResult($lockedProduction->load(['requirements', 'tasks']), []);
            }
            $this->rescheduler->rescheduleLocked($actor, $lockedWorkspace, $lockedProduction, $plannedFor, $scope);

            return new ProductionMutationResult($lockedProduction->fresh(['requirements', 'tasks']), $changed ? [$lockedProduction->id] : []);
        });
    }

    private function validateDate(string $date): void
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = \DateTimeImmutable::getLastErrors();

        if (
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1
            || $parsed === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format('Y-m-d') !== $date
        ) {
            throw ValidationException::withMessages(['planned_for' => __('production_bench.production.validation.planned_date_format')]);
        }
    }
}
