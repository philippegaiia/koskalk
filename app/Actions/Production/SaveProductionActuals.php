<?php

namespace App\Actions\Production;

use App\Models\ProductionConsumption;
use App\Models\ProductionRequirement;
use App\Models\ProductionRun;
use App\Models\StockLot;
use App\Models\User;
use App\Models\Workspace;
use App\ProductionConsumptionKind;
use App\ProductionRunStatus;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveProductionActuals
{
    public function __construct(
        private readonly ProductionBenchAccess $access,
    ) {}

    /**
     * Save actual consumption rows as draft data during production.
     *
     * Rows are upserted by requirement and lot; a zero quantity removes the
     * row. No stock movement is posted here: the terminal action consumes the
     * recorded rows.
     *
     * @param  array<int, array{production_requirement_id: int, stock_lot_id?: int|null, quantity: string, note?: string|null}>  $rows
     */
    public function handle(User $actor, ProductionRun $production, array $rows): ProductionRun
    {
        $workspace = $production->workspace;

        if (! $workspace instanceof Workspace) {
            throw ValidationException::withMessages([
                'production' => 'The production workspace could not be found.',
            ]);
        }

        $this->access->assertWritable($actor, $workspace);

        return DB::transaction(function () use ($actor, $production, $rows): ProductionRun {
            $lockedWorkspace = Workspace::withoutGlobalScopes()
                ->lockForUpdate()
                ->find($production->workspace_id);
            $lockedProduction = ProductionRun::query()
                ->lockForUpdate()
                ->findOrFail($production->id);

            if (! $lockedWorkspace instanceof Workspace) {
                throw ValidationException::withMessages([
                    'production' => 'The production workspace could not be found.',
                ]);
            }

            $this->access->assertWritable($actor, $lockedWorkspace);

            if ($lockedProduction->status !== ProductionRunStatus::InProduction) {
                throw ValidationException::withMessages([
                    'production' => 'Actual consumption can only be recorded while the production is running.',
                ]);
            }

            $requirements = ProductionRequirement::query()
                ->where('production_run_id', $lockedProduction->id)
                ->get()
                ->keyBy('id');

            foreach ($rows as $index => $row) {
                $requirement = $requirements->get($row['production_requirement_id'] ?? null);

                if (! $requirement instanceof ProductionRequirement) {
                    throw ValidationException::withMessages([
                        "rows.{$index}.production_requirement_id" => 'The requirement does not belong to this production.',
                    ]);
                }

                $quantity = $this->normalizeQuantity($row['quantity'] ?? null, $index);

                if ($requirement->ingredient_id === null
                    && preg_match('/^\d+$/', $quantity) !== 1) {
                    throw ValidationException::withMessages([
                        "rows.{$index}.quantity" => 'Packaging actual quantities must be whole units.',
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

                $lot = $this->resolveLot($requirement, $row['stock_lot_id'] ?? null, $lockedWorkspace, $index);

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
            ->find((int) $stockLotId);

        if (! $lot instanceof StockLot) {
            throw ValidationException::withMessages([
                "rows.{$index}.stock_lot_id" => 'The stock lot does not belong to this workspace.',
            ]);
        }

        $subjectMatches = $requirement->ingredient_id !== null
            ? $lot->ingredient_id === $requirement->ingredient_id
            : $lot->packaging_item_id === $requirement->packaging_item_id;

        if (! $subjectMatches) {
            throw ValidationException::withMessages([
                "rows.{$index}.stock_lot_id" => 'The stock lot does not match the requirement subject.',
            ]);
        }

        return $lot;
    }

    private function normalizeQuantity(mixed $value, int $index): string
    {
        $quantity = trim((string) $value);

        if (preg_match('/^\d+(?:\.\d+)?$/', $quantity) !== 1) {
            throw ValidationException::withMessages([
                "rows.{$index}.quantity" => 'The actual quantity must be a positive decimal value.',
            ]);
        }

        return $quantity;
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
