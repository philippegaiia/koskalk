<?php

namespace App\Actions\Production;

use App\Enums\ProductionRunStatus;
use App\Models\ProductionRun;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Production\ProductionDateRescheduler;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RescheduleProduction
{
    public function __construct(
        private readonly ProductionBenchAccess $access,
        private readonly ProductionDateRescheduler $rescheduler,
    ) {}

    public function handle(User $actor, ProductionRun $production, string $plannedFor): ProductionRun
    {
        $this->validateDate($plannedFor);
        $workspace = $production->workspace;

        if ($workspace === null) {
            throw ValidationException::withMessages(['production' => __('production_bench.production.workspace_missing')]);
        }

        $this->access->assertWritable($actor, $workspace);

        return DB::transaction(function () use ($actor, $plannedFor, $production): ProductionRun {
            $lockedProduction = ProductionRun::query()->lockForUpdate()->findOrFail($production->id);
            $lockedWorkspace = Workspace::withoutGlobalScopes()->lockForUpdate()->find($lockedProduction->workspace_id);

            if ($lockedWorkspace === null) {
                throw ValidationException::withMessages(['production' => __('production_bench.production.workspace_missing')]);
            }

            $this->access->assertWritable($actor, $lockedWorkspace);

            if (! in_array($lockedProduction->status, [
                ProductionRunStatus::Draft,
                ProductionRunStatus::Scheduled,
                ProductionRunStatus::Reserved,
            ], true)) {
                throw ValidationException::withMessages([
                    'production' => __('production_bench.production.validation.reschedule_after_start'),
                ]);
            }

            $this->rescheduler->rescheduleLocked($lockedWorkspace, $lockedProduction, $plannedFor);

            return $lockedProduction->fresh(['requirements', 'tasks']);
        }, attempts: 5);
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
