<?php

namespace App\Services\Production;

use App\Models\ProductionFormulaLine;
use App\Models\ProductionRequirement;
use App\Models\ProductionRun;
use App\ProductionRequirementKind;
use Illuminate\Validation\ValidationException;

class ProductionSnapshotRescaler
{
    private const int GuardScale = 18;

    private const int StorageScale = 9;

    /**
     * Rescale a locked production's owned snapshots in place.
     *
     * Formula lines and requirements keep their row IDs so released or
     * cancelled reservation audit rows survive. The source recipe version is
     * never reopened: quantities are derived from the snapshotted percentages
     * and components-per-unit stored on each row.
     */
    public function rescale(
        ProductionRun $lockedProduction,
        string $basisQuantityGrams,
        int $expectedUnits,
    ): void {
        $lines = ProductionFormulaLine::query()
            ->where('production_run_id', $lockedProduction->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($lines as $line) {
            $mass = $this->roundStorage(
                bcdiv(
                    bcmul($basisQuantityGrams, (string) $line->basis_percentage_snapshot, self::GuardScale),
                    '100',
                    self::GuardScale,
                ),
            );

            $line->update(['planned_mass_grams' => $mass]);
        }

        $requirements = ProductionRequirement::query()
            ->where('production_run_id', $lockedProduction->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($requirements as $requirement) {
            if ($requirement->kind === ProductionRequirementKind::Ingredient) {
                $percentage = $requirement->percentage_snapshot;

                if ($percentage === null) {
                    throw ValidationException::withMessages([
                        'production' => 'The production snapshot is incomplete and cannot be corrected.',
                    ]);
                }

                $requirement->update([
                    'required_mass_grams' => $this->roundStorage(
                        bcdiv(
                            bcmul($basisQuantityGrams, (string) $percentage, self::GuardScale),
                            '100',
                            self::GuardScale,
                        ),
                    ),
                ]);

                continue;
            }

            $componentsPerUnit = $requirement->components_per_unit_snapshot;

            if ($componentsPerUnit === null) {
                throw ValidationException::withMessages([
                    'production' => 'The production snapshot is incomplete and cannot be corrected.',
                ]);
            }

            $requirement->update([
                'required_units' => $this->ceilPositive(
                    bcmul((string) $expectedUnits, (string) $componentsPerUnit, self::GuardScale),
                ),
            ]);
        }
    }

    private function roundStorage(string $value): string
    {
        $increment = '0.'.str_repeat('0', self::StorageScale).'5';
        $adjusted = bcadd($value, $increment, self::StorageScale + 1);

        return bcadd($adjusted, '0', self::StorageScale);
    }

    private function ceilPositive(string $value): int
    {
        $whole = bcdiv($value, '1', 0);

        if (bccomp($value, $whole, self::GuardScale) > 0) {
            $whole = bcadd($whole, '1', 0);
        }

        return (int) $whole;
    }
}
