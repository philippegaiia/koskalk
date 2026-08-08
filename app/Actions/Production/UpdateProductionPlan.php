<?php

namespace App\Actions\Production;

use App\Enums\MassUnit;
use App\Enums\ProductionRunStatus;
use App\Models\ProductionRun;
use App\Models\User;
use App\Models\Workspace;
use App\Services\MassConverter;
use App\Services\Production\ProductionSnapshotRescaler;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class UpdateProductionPlan
{
    public function __construct(
        private readonly ProductionBenchAccess $access,
        private readonly MassConverter $massConverter,
        private readonly ProductionSnapshotRescaler $rescaler,
    ) {}

    public function handle(
        User $actor,
        ProductionRun $production,
        string $basisInputValue,
        MassUnit|string $basisInputUnit,
        int|string|float $expectedUnits,
        ?string $plannedFor = null,
        ?string $notes = null,
    ): ProductionRun {
        $workspace = $production->workspace;

        if ($workspace === null) {
            throw ValidationException::withMessages([
                'production' => 'The production workspace could not be found.',
            ]);
        }

        $this->access->assertWritable($actor, $workspace);
        $expectedUnits = $this->normalizeExpectedUnits($expectedUnits);
        $this->validateInput($basisInputValue, $plannedFor);
        $massUnit = $this->massUnit($basisInputUnit);
        $basisQuantityGrams = $this->massConverter->toGrams($basisInputValue, $massUnit);

        return DB::transaction(function () use (
            $actor,
            $basisInputValue,
            $basisQuantityGrams,
            $expectedUnits,
            $massUnit,
            $notes,
            $plannedFor,
            $production,
        ): ProductionRun {
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
            ], true)) {
                throw ValidationException::withMessages([
                    'production' => 'Only draft or planned productions can be updated.',
                ]);
            }

            $this->assertNoActiveReservations($lockedProduction);

            $this->rescaler->rescale($lockedProduction, $basisQuantityGrams, $expectedUnits);

            $lockedProduction->update([
                'basis_quantity_grams' => $basisQuantityGrams,
                'basis_input_value' => $basisInputValue,
                'basis_input_unit' => $massUnit,
                'expected_units' => $expectedUnits,
                'planned_for' => $plannedFor,
                'notes' => $notes,
            ]);

            return $lockedProduction->fresh(['requirements', 'formulaLines']);
        }, attempts: 5);
    }

    private function massUnit(MassUnit|string $unit): MassUnit
    {
        try {
            return $unit instanceof MassUnit ? $unit : MassUnit::fromInput($unit);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages([
                'basis_input_unit' => 'Choose a supported mass unit.',
            ]);
        }
    }

    private function validateInput(string $basisInputValue, ?string $plannedFor): void
    {
        if (
            preg_match('/^\d+(?:\.\d+)?$/', trim($basisInputValue)) !== 1
            || bccomp(trim($basisInputValue), '0', 18) <= 0
        ) {
            throw ValidationException::withMessages([
                'basis_input_value' => 'The production basis must be greater than zero.',
            ]);
        }

        if ($plannedFor !== null && ! $this->isValidDate($plannedFor)) {
            throw ValidationException::withMessages([
                'planned_for' => 'The production date must use YYYY-MM-DD format.',
            ]);
        }
    }

    private function normalizeExpectedUnits(int|string|float $expectedUnits): int
    {
        $normalized = trim((string) $expectedUnits);

        if (preg_match('/^[1-9]\d*$/', $normalized) !== 1) {
            throw ValidationException::withMessages([
                'expected_units' => 'Expected units must be a positive whole number.',
            ]);
        }

        return (int) $normalized;
    }

    private function isValidDate(string $date): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return false;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = \DateTimeImmutable::getLastErrors();

        return $parsed !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $parsed->format('Y-m-d') === $date;
    }

    private function assertNoActiveReservations(ProductionRun $production): void
    {
        if (! Schema::hasTable('stock_reservations')) {
            return;
        }

        $hasActiveReservations = DB::table('stock_reservations')
            ->where('production_run_id', $production->id)
            ->whereNotIn('status', ['released', 'cancelled'])
            ->exists();

        if ($hasActiveReservations) {
            throw ValidationException::withMessages([
                'production' => 'A production with active stock reservations cannot be changed.',
            ]);
        }
    }
}
