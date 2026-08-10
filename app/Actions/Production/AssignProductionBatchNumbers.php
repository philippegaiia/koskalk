<?php

namespace App\Actions\Production;

use App\Enums\ProductionRunStatus;
use App\Models\ProductionRun;
use App\Models\ProductionRunNumberIssuance;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Production\ProductionRunNumberService;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssignProductionBatchNumbers
{
    public function __construct(
        private readonly ProductionBenchAccess $access,
        private readonly ProductionRunNumberService $numbers,
    ) {}

    /**
     * @param  array<int, int|string>  $productionIds
     * @return array{assigned: int, already_assigned: int}
     */
    public function handle(User $actor, Workspace $workspace, array $productionIds): array
    {
        $productionIds = $this->normalizeProductionIds($productionIds);
        $this->access->assertWritable($actor, $workspace);

        return DB::transaction(function () use ($actor, $productionIds, $workspace): array {
            [$lockedWorkspace, $settings] = $this->numbers->lockWorkspaceAndSettings($workspace);
            $this->access->assertWritable($actor, $lockedWorkspace);

            $productions = ProductionRun::query()
                ->where('workspace_id', $lockedWorkspace->id)
                ->whereIn('id', $productionIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($productions->count() !== count($productionIds)) {
                throw ValidationException::withMessages([
                    'production_ids' => __('production_bench.production.validation.batch_numbers_productions_missing'),
                ]);
            }

            $alreadyAssigned = $productions->filter(fn (ProductionRun $production): bool => $production->batch_number !== null);
            $eligible = $productions
                ->reject(fn (ProductionRun $production): bool => $production->batch_number !== null)
                ->sortBy([
                    ['planned_for', 'asc'],
                    ['id', 'asc'],
                ])
                ->values();

            $this->assertEligible($eligible);

            if ($eligible->isNotEmpty() && $settings->next_permanent_serial > PHP_INT_MAX - $eligible->count()) {
                throw ValidationException::withMessages([
                    'next_permanent_serial' => __('production_bench.production.validation.batch_numbers_counter_exhausted'),
                ]);
            }

            $candidates = $this->numbers->permanentCandidates($settings, $eligible->count());

            if ($this->numbers->identityExists($lockedWorkspace->id, $candidates)) {
                throw ValidationException::withMessages([
                    'batch_number' => __('production_bench.production.validation.batch_numbers_in_use'),
                ]);
            }

            foreach ($eligible as $offset => $production) {
                $serial = $settings->next_permanent_serial + $offset;
                $assignment = $this->numbers->permanentAssignmentValues($candidates[$offset], $serial, $actor);
                ProductionRunNumberIssuance::query()->create([
                    'workspace_id' => $lockedWorkspace->id,
                    'production_run_id' => $production->id,
                    'batch_number' => $assignment['batch_number'],
                    'serial' => $assignment['batch_number_serial'],
                    'issued_by_user_id' => $assignment['batch_number_assigned_by_user_id'],
                    'issued_at' => $assignment['batch_number_assigned_at'],
                ]);
                $production->update($assignment);
            }

            if ($eligible->isNotEmpty()) {
                $settings->update([
                    'next_permanent_serial' => $settings->next_permanent_serial + $eligible->count(),
                ]);
            }

            return [
                'assigned' => $eligible->count(),
                'already_assigned' => $alreadyAssigned->count(),
            ];
        }, attempts: 5);
    }

    /**
     * @param  Collection<int, ProductionRun>  $productions
     */
    private function assertEligible(Collection $productions): void
    {
        foreach ($productions as $production) {
            if (! in_array($production->status, [ProductionRunStatus::Scheduled, ProductionRunStatus::Reserved], true)) {
                throw ValidationException::withMessages([
                    'production_ids' => __('production_bench.production.validation.batch_numbers_status_invalid'),
                ]);
            }

            if ($production->planned_for === null) {
                throw ValidationException::withMessages([
                    'production_ids' => __('production_bench.production.validation.batch_numbers_planned_date_required'),
                ]);
            }
        }
    }

    /**
     * @param  array<int, int|string>  $productionIds
     * @return array<int, int>
     */
    private function normalizeProductionIds(array $productionIds): array
    {
        $normalized = [];

        foreach ($productionIds as $productionId) {
            $value = trim((string) $productionId);

            if (preg_match('/^[1-9]\d*$/', $value) !== 1 || strlen($value) > 18) {
                throw ValidationException::withMessages([
                    'production_ids' => __('production_bench.production.validation.batch_numbers_selection_invalid'),
                ]);
            }

            $normalized[] = (int) $value;
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_NUMERIC);

        if ($normalized === []) {
            throw ValidationException::withMessages([
                'production_ids' => __('production_bench.production.validation.batch_numbers_selection_required'),
            ]);
        }

        return $normalized;
    }
}
