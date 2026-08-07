<?php

namespace App\Services\Production;

use App\Models\Ingredient;
use App\Models\ProductionConsumption;
use App\Models\ProductionRun;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Workspace;
use App\ProductionConsumptionKind;
use App\ProductionRunStatus;
use App\StockLotOrigin;
use App\StockLotStatus;
use App\StockMovementType;
use App\StockReservationStatus;
use App\StockUnitKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductionCompletionService
{
    private const int GuardScale = 18;

    /**
     * Complete an in-production run atomically: post consumption movements,
     * release unused reservations, snapshot actual costs, create the output
     * lot (coded with the permanent batch number), post the output movement,
     * and close the run. Either every step succeeds or none does.
     */
    public function complete(
        User $actor,
        ProductionRun $production,
        string $actualOutputQuantity,
        string $manufactureDate,
        ?int $outputIngredientId = null,
    ): ProductionRun {
        return DB::transaction(function () use (
            $actor,
            $actualOutputQuantity,
            $manufactureDate,
            $outputIngredientId,
            $production,
        ): ProductionRun {
            $workspace = Workspace::withoutGlobalScopes()
                ->lockForUpdate()
                ->findOrFail($production->workspace_id);
            $lockedProduction = ProductionRun::query()
                ->lockForUpdate()
                ->findOrFail($production->id);

            $this->assertCompletable($lockedProduction, $workspace, $actualOutputQuantity, $manufactureDate, $outputIngredientId);

            $consumption = ProductionConsumption::query()
                ->where('production_run_id', $lockedProduction->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            // 1. Post actual consumption movements (may create negative stock).
            $currency = null;
            $ingredientTotal = '0';
            $packagingTotal = '0';
            $workspaceDefaultCurrency = strtoupper((string) ($workspace->default_currency ?? ''));

            foreach ($consumption as $row) {
                $lot = StockLot::query()
                    ->withoutGlobalScopes()
                    ->lockForUpdate()
                    ->findOrFail($row->stock_lot_id);

                $pricePerUnit = $this->pricePerUnit($lot, $row);
                $lineCost = $this->lineCost($row, $pricePerUnit);

                if ($pricePerUnit !== null) {
                    $lineCurrency = $this->lineCurrency($lot, $workspaceDefaultCurrency);

                    if ($currency === null) {
                        $currency = $lineCurrency;
                    } elseif ($currency !== $lineCurrency) {
                        throw ValidationException::withMessages([
                            'production' => 'Production costing mixes currencies: the lot '.$lot->internal_lot_code.' is priced in '.$lineCurrency.' while the rest of the batch is in '.$currency.'. Correct the lot cost or use the workspace costing values.',
                        ]);
                    }
                }

                if ($row->kind === ProductionConsumptionKind::Ingredient) {
                    $ingredientTotal = bcadd($ingredientTotal, $lineCost, 9);
                } else {
                    $packagingTotal = bcadd($packagingTotal, $lineCost, 9);
                }

                StockMovement::query()->create([
                    'stock_lot_id' => $lot->id,
                    'workspace_id' => $workspace->id,
                    'type' => StockMovementType::ProductionConsumption,
                    'quantity_delta' => '-'.$row->quantity,
                    'original_quantity' => $row->quantity,
                    'original_unit' => $row->unit_snapshot,
                    'occurred_at' => now(),
                    'actor_user_id' => $actor->id,
                    'source_type' => (new ProductionRun)->getMorphClass(),
                    'source_id' => $lockedProduction->id,
                    'idempotency_key' => (string) Str::uuid(),
                    'note' => $row->note,
                ]);

                $row->update([
                    'price_per_unit' => $pricePerUnit,
                    'line_cost' => $lineCost,
                ]);
            }

            // 2. Release every active reservation: consumption movements are
            //    now the stock truth.
            StockReservation::query()
                ->where('production_run_id', $lockedProduction->id)
                ->where('status', StockReservationStatus::Active)
                ->lockForUpdate()
                ->get()
                ->each(function (StockReservation $reservation): void {
                    $reservation->update([
                        'status' => StockReservationStatus::Released,
                        'released_at' => now(),
                    ]);
                });

            // 3. Create the output lot, coded with the permanent batch number.
            //    Intermediate lots carry the producing batch's cost per gram so
            //    a downstream production prices them from this batch.
            $isIntermediate = $outputIngredientId !== null;
            $outputQuantity = $this->normalizeOutputQuantity($actualOutputQuantity, $isIntermediate);
            $intermediateCostPerGram = $isIntermediate && bccomp($ingredientTotal, '0', 18) > 0
                ? bcdiv($ingredientTotal, $outputQuantity, 9)
                : null;

            $outputLot = StockLot::query()->create([
                'workspace_id' => $workspace->id,
                'ingredient_id' => $isIntermediate ? $outputIngredientId : null,
                'packaging_item_id' => null,
                'recipe_id' => $isIntermediate ? null : $lockedProduction->recipe_id,
                'production_run_id' => $lockedProduction->id,
                'internal_lot_code' => $lockedProduction->batch_number,
                'origin' => StockLotOrigin::ProductionOutput,
                'unit_kind' => $isIntermediate ? StockUnitKind::Mass : StockUnitKind::Count,
                'status' => StockLotStatus::Quarantined,
                'stocked_at' => $manufactureDate,
                'expires_at' => null,
                'available_from' => null,
                'released_at' => null,
                'provenance_complete' => true,
                'historical_unit_cost' => $intermediateCostPerGram,
                'costing_unit_cost' => $intermediateCostPerGram,
                'currency' => $currency,
                'costing_currency' => $currency,
            ]);

            // 4. Post the production-output movement.
            $outputLot->movements()->create([
                'workspace_id' => $workspace->id,
                'type' => StockMovementType::ProductionOutput,
                'quantity_delta' => $outputQuantity,
                'original_quantity' => $outputQuantity,
                'original_unit' => $isIntermediate ? 'g' : 'unit',
                'occurred_at' => now(),
                'actor_user_id' => $actor->id,
                'source_type' => (new ProductionRun)->getMorphClass(),
                'source_id' => $lockedProduction->id,
                'idempotency_key' => (string) Str::uuid(),
            ]);

            // 5. Close the run with the actual cost snapshot.
            $totalCost = bcadd($ingredientTotal, $packagingTotal, 9);
            $costPerUnit = bccomp($outputQuantity, '0', self::GuardScale) > 0
                ? bcdiv($totalCost, $outputQuantity, 9)
                : null;

            $lockedProduction->update([
                'status' => ProductionRunStatus::Completed,
                'completed_at' => now(),
                'completed_by_user_id' => $actor->id,
                'manufacture_date' => $manufactureDate,
                'actual_output_units' => $isIntermediate ? null : (int) $outputQuantity,
                'actual_output_mass_grams' => $isIntermediate ? $outputQuantity : null,
                'cost_currency' => $currency ?: null,
                'actual_ingredient_total' => $ingredientTotal,
                'actual_packaging_total' => $packagingTotal,
                'actual_total_cost' => $totalCost,
                'actual_cost_per_unit' => $costPerUnit,
            ]);

            return $lockedProduction->fresh(['requirements', 'consumption', 'outputLot']);
        }, attempts: 5);
    }

    private function assertCompletable(
        ProductionRun $production,
        Workspace $workspace,
        string $actualOutputQuantity,
        string $manufactureDate,
        ?int $outputIngredientId,
    ): void {
        if ($production->status !== ProductionRunStatus::InProduction) {
            throw ValidationException::withMessages([
                'production' => 'Only a running production can be completed.',
            ]);
        }

        if ($production->batch_number === null) {
            throw ValidationException::withMessages([
                'production' => 'The permanent batch number is required before completion.',
            ]);
        }

        if (! $this->isValidDate($manufactureDate)) {
            throw ValidationException::withMessages([
                'manufacture_date' => 'The manufacture date must use YYYY-MM-DD format.',
            ]);
        }

        $isIntermediate = $outputIngredientId !== null;

        if ($isIntermediate) {
            $ingredient = Ingredient::query()
                ->withoutGlobalScopes()
                ->where(fn ($query) => $query->whereNull('workspace_id')->orWhere('workspace_id', $workspace->id))
                ->find($outputIngredientId);

            if (! $ingredient instanceof Ingredient) {
                throw ValidationException::withMessages([
                    'output_ingredient_id' => 'The intermediate ingredient must belong to this workspace.',
                ]);
            }
        }

        $this->normalizeOutputQuantity($actualOutputQuantity, $isIntermediate);

        $requirements = $production->requirements()->get();

        if ($requirements->isEmpty()) {
            throw ValidationException::withMessages([
                'production' => 'This production has no requirements to consume.',
            ]);
        }

        $consumedRequirementIds = ProductionConsumption::query()
            ->where('production_run_id', $production->id)
            ->pluck('production_requirement_id')
            ->all();

        $missing = $requirements->reject(
            fn ($requirement): bool => in_array($requirement->id, $consumedRequirementIds, true),
        );

        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                'production' => 'Record actual quantities for every requirement before completing: '.$missing->pluck('subject_name_snapshot')->implode(', ').'.',
            ]);
        }

        $lotlessRows = ProductionConsumption::query()
            ->where('production_run_id', $production->id)
            ->whereNull('stock_lot_id')
            ->exists();

        if ($lotlessRows) {
            throw ValidationException::withMessages([
                'production' => 'Every actual quantity must reference a stock lot before completing.',
            ]);
        }
    }

    private function normalizeOutputQuantity(string $value, bool $isIntermediate): string
    {
        if (preg_match('/^\d+(?:\.\d+)?$/', trim($value)) !== 1 || bccomp(trim($value), '0', self::GuardScale) <= 0) {
            throw ValidationException::withMessages([
                'actual_output_quantity' => 'The actual output quantity must be greater than zero.',
            ]);
        }

        if (! $isIntermediate && preg_match('/^\d+$/', trim($value)) !== 1) {
            throw ValidationException::withMessages([
                'actual_output_quantity' => 'Finished output must be a whole number of units.',
            ]);
        }

        return trim($value);
    }

    private function pricePerUnit(StockLot $lot, ProductionConsumption $row): ?string
    {
        // Prefer the workspace-converted costing value stored at receipt time;
        // fall back to the source-currency historical cost.
        $price = $lot->costing_unit_cost ?? $lot->historical_unit_cost;

        return $price === null ? null : (string) $price;
    }

    private function lineCurrency(StockLot $lot, string $workspaceDefaultCurrency): string
    {
        return strtoupper((string) ($lot->costing_unit_cost !== null
            ? ($lot->costing_currency ?? $workspaceDefaultCurrency)
            : ($lot->currency ?? '')));
    }

    private function lineCost(ProductionConsumption $row, ?string $pricePerUnit): string
    {
        if ($pricePerUnit === null) {
            return '0';
        }

        // Receipts store canonical grams and historical_unit_cost is cost per
        // gram, so ingredient cost is grams × price-per-gram.
        return bcmul($row->quantity, $pricePerUnit, 9);
    }

    private function isValidDate(string $date): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return false;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}
