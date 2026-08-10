<?php

namespace App\Actions\Production;

use App\Enums\ProductionConsumptionKind;
use App\Enums\ProductionFormulaComponent;
use App\Enums\ProductionRunStatus;
use App\Models\ProductionConsumption;
use App\Models\ProductionFormulaLine;
use App\Models\ProductionRequirement;
use App\Models\ProductionRun;
use App\Models\StockLot;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Production\ConsumableStockLotPolicy;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveProductionActuals
{
    public function __construct(
        private readonly ProductionBenchAccess $access,
        private readonly ConsumableStockLotPolicy $lotPolicy,
    ) {}

    /**
     * Save actual consumption rows as draft data during production.
     *
     * Rows are upserted by requirement and lot; a zero quantity removes the
     * row. No stock movement is posted here: the terminal action consumes the
     * recorded rows.
     *
     * @param  array<int, array{production_requirement_id: int, stock_lot_id?: int|null, quantity: string, note?: string|null}>  $rows
     * @param  array<int, array{production_formula_line_id: int, actual_mass_grams: string}>  $calculatedRows
     */
    public function handle(User $actor, ProductionRun $production, array $rows, array $calculatedRows = []): ProductionRun
    {
        $workspace = $production->workspace;

        if (! $workspace instanceof Workspace) {
            throw ValidationException::withMessages([
                'production' => __('production_bench.production.workspace_missing'),
            ]);
        }

        $this->access->assertWritable($actor, $workspace);

        return DB::transaction(function () use ($actor, $calculatedRows, $production, $rows): ProductionRun {
            $lockedWorkspace = Workspace::withoutGlobalScopes()
                ->lockForUpdate()
                ->find($production->workspace_id);
            $lockedProduction = ProductionRun::query()
                ->lockForUpdate()
                ->findOrFail($production->id);

            if (! $lockedWorkspace instanceof Workspace) {
                throw ValidationException::withMessages([
                    'production' => __('production_bench.production.workspace_missing'),
                ]);
            }

            $this->access->assertWritable($actor, $lockedWorkspace);

            if ($lockedProduction->status !== ProductionRunStatus::InProduction) {
                throw ValidationException::withMessages([
                    'production' => __('production_bench.production.validation.actuals_running_only'),
                ]);
            }

            $requirements = ProductionRequirement::query()
                ->where('production_run_id', $lockedProduction->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $formulaLines = ProductionFormulaLine::query()
                ->where('production_run_id', $lockedProduction->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $calculatedLineIds = [];

            foreach ($calculatedRows as $index => $row) {
                $lineId = filter_var($row['production_formula_line_id'] ?? null, FILTER_VALIDATE_INT);
                $line = $lineId === false ? null : $formulaLines->get((int) $lineId);

                if (! $line instanceof ProductionFormulaLine) {
                    throw ValidationException::withMessages([
                        "calculatedRows.{$index}.production_formula_line_id" => __('production_bench.production.validation.actual_calculated_line_invalid'),
                    ]);
                }

                if ($line->component !== ProductionFormulaComponent::Water) {
                    throw ValidationException::withMessages([
                        "calculatedRows.{$index}.production_formula_line_id" => __('production_bench.production.validation.actual_calculated_water_only'),
                    ]);
                }

                if (in_array($line->id, $calculatedLineIds, true)) {
                    throw ValidationException::withMessages([
                        "calculatedRows.{$index}.production_formula_line_id" => __('production_bench.production.validation.actual_calculated_line_duplicate'),
                    ]);
                }

                $calculatedLineIds[] = $line->id;
                $line->update([
                    'actual_mass_grams' => $this->normalizeCalculatedQuantity($row['actual_mass_grams'] ?? null, $index),
                ]);
            }

            foreach ($rows as $index => $row) {
                $requirement = $requirements->get($row['production_requirement_id'] ?? null);

                if (! $requirement instanceof ProductionRequirement) {
                    throw ValidationException::withMessages([
                        "rows.{$index}.production_requirement_id" => __('production_bench.production.validation.actual_requirement_invalid'),
                    ]);
                }

                $quantity = $this->normalizeQuantity($row['quantity'] ?? null, $index);

                if ($requirement->ingredient_id === null
                    && bccomp($quantity, bcdiv($quantity, '1', 0), 9) !== 0) {
                    throw ValidationException::withMessages([
                        "rows.{$index}.quantity" => __('production_bench.production.validation.actual_packaging_whole'),
                    ]);
                }

                if (bccomp($quantity, '0', 9) === 0) {
                    ProductionConsumption::query()
                        ->where('production_run_id', $lockedProduction->id)
                        ->where('production_requirement_id', $requirement->id)
                        ->where('stock_lot_id', $row['stock_lot_id'] ?? null)
                        ->delete();

                    continue;
                }

                $lot = $this->resolveLot(
                    $requirement,
                    $lockedProduction,
                    $row['stock_lot_id'] ?? null,
                    $lockedWorkspace,
                    $index,
                );

                if (! $lot instanceof StockLot) {
                    throw ValidationException::withMessages([
                        "rows.{$index}.stock_lot_id" => __('production_bench.production.validation.actual_lot_required'),
                    ]);
                }

                ProductionConsumption::query()->updateOrCreate(
                    [
                        'production_run_id' => $lockedProduction->id,
                        'production_requirement_id' => $requirement->id,
                        'stock_lot_id' => $lot?->id,
                    ],
                    [
                        'kind' => $requirement->ingredient_id !== null
                            ? ProductionConsumptionKind::Ingredient
                            : ProductionConsumptionKind::Packaging,
                        'subject_name_snapshot' => $requirement->subject_name_snapshot,
                        'quantity' => $quantity,
                        'unit_snapshot' => $requirement->ingredient_id !== null ? 'g' : 'unit',
                        'recorded_by_user_id' => $actor->id,
                        'note' => $this->nullableString($row['note'] ?? null),
                    ],
                );
            }

            return $lockedProduction->fresh(['requirements', 'consumption']);
        }, attempts: 5);
    }

    private function resolveLot(
        ProductionRequirement $requirement,
        ProductionRun $production,
        mixed $stockLotId,
        Workspace $workspace,
        int $index,
    ): ?StockLot {
        if ($stockLotId === null || $stockLotId === '') {
            return null;
        }

        $lot = StockLot::query()
            ->withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->lockForUpdate()
            ->find((int) $stockLotId);

        if (! $lot instanceof StockLot) {
            throw ValidationException::withMessages([
                "rows.{$index}.stock_lot_id" => __('production_bench.production.validation.actual_stock_lot_workspace_invalid'),
            ]);
        }

        $this->lotPolicy->assertConsumable($lot, now(), "rows.{$index}.stock_lot_id");

        $subjectMatches = $requirement->ingredient_id !== null
            ? $lot->ingredient_id === $requirement->ingredient_id && $lot->packaging_item_id === null
            : $lot->packaging_item_id === $requirement->packaging_item_id && $lot->ingredient_id === null;

        if (! $subjectMatches) {
            throw ValidationException::withMessages([
                "rows.{$index}.stock_lot_id" => __('production_bench.production.validation.actual_stock_lot_subject_invalid'),
            ]);
        }

        return $lot;
    }

    private function normalizeQuantity(mixed $value, int $index): string
    {
        $quantity = trim((string) $value);

        if (preg_match('/^\d+(?:\.\d+)?$/', $quantity) !== 1) {
            throw ValidationException::withMessages([
                "rows.{$index}.quantity" => __('production_bench.production.validation.actual_quantity_positive_decimal'),
            ]);
        }

        return $quantity;
    }

    private function normalizeCalculatedQuantity(mixed $value, int $index): string
    {
        $quantity = trim((string) $value);

        if (preg_match('/^\d+(?:\.\d+)?$/', $quantity) !== 1 || bccomp($quantity, '0', 9) <= 0) {
            throw ValidationException::withMessages([
                "calculatedRows.{$index}.actual_mass_grams" => __('production_bench.production.validation.calculated_actual_positive'),
            ]);
        }

        return bcadd($quantity, '0', 9);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
