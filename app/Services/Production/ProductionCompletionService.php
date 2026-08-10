<?php

namespace App\Services\Production;

use App\Enums\ProductionConsumptionKind;
use App\Enums\ProductionFormulaComponent;
use App\Enums\ProductionOutputType;
use App\Enums\ProductionRunStatus;
use App\Enums\StockLotOrigin;
use App\Enums\StockLotStatus;
use App\Enums\StockMovementType;
use App\Enums\StockReservationStatus;
use App\Enums\StockUnitKind;
use App\Models\Ingredient;
use App\Models\ProductionConsumption;
use App\Models\ProductionFormulaLine;
use App\Models\ProductionRequirement;
use App\Models\ProductionRun;
use App\Models\ProductionTask;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductionCompletionService
{
    private const int GuardScale = 18;

    public function __construct(private readonly ConsumableStockLotPolicy $lotPolicy) {}

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
        ?string $estimatedReadyOn = null,
        ?int $outputIngredientId = null,
    ): ProductionRun {
        return DB::transaction(function () use (
            $actor,
            $actualOutputQuantity,
            $manufactureDate,
            $estimatedReadyOn,
            $outputIngredientId,
            $production,
        ): ProductionRun {
            $workspace = Workspace::withoutGlobalScopes()
                ->lockForUpdate()
                ->findOrFail($production->workspace_id);
            $lockedProduction = ProductionRun::query()
                ->lockForUpdate()
                ->findOrFail($production->id);

            $outputConfiguration = $this->resolveOutputConfiguration($lockedProduction, $outputIngredientId);
            $resolvedOutputIngredientId = $outputConfiguration['output_ingredient_id'];
            $this->defaultCalculatedLyeActuals($actor, $lockedProduction);
            $this->assertCompletable($lockedProduction, $workspace, $actualOutputQuantity, $manufactureDate, $resolvedOutputIngredientId);

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
                $this->assertEligibleConsumptionLot($lot, $manufactureDate);

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
            $isIntermediate = $outputConfiguration['production_output_type'] === ProductionOutputType::ManufacturedIngredient;
            $outputQuantity = $this->normalizeOutputQuantity($actualOutputQuantity, $isIntermediate);
            $intermediateCostPerGram = $isIntermediate && bccomp($ingredientTotal, '0', 18) > 0
                ? bcdiv($ingredientTotal, $outputQuantity, 9)
                : null;
            $confirmedReadyOn = $this->confirmedReadyOn($lockedProduction, $manufactureDate, $estimatedReadyOn);

            $outputLot = StockLot::query()->create([
                'workspace_id' => $workspace->id,
                'ingredient_id' => $isIntermediate ? $resolvedOutputIngredientId : null,
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
                'estimated_ready_on' => $confirmedReadyOn,
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
                'estimated_ready_on' => $confirmedReadyOn,
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

    /**
     * @return array{production_output_type: ProductionOutputType, output_ingredient_id: int|null}
     */
    private function resolveOutputConfiguration(ProductionRun $production, ?int $legacyOutputIngredientId): array
    {
        $outputType = $production->production_output_type;

        if (! $outputType instanceof ProductionOutputType) {
            return [
                'production_output_type' => $legacyOutputIngredientId === null
                    ? ProductionOutputType::FinishedProduct
                    : ProductionOutputType::ManufacturedIngredient,
                'output_ingredient_id' => $legacyOutputIngredientId,
            ];
        }

        if ($outputType === ProductionOutputType::ManufacturedIngredient) {
            if ($production->output_ingredient_id === null) {
                throw ValidationException::withMessages([
                    'output_ingredient_id' => __('production_bench.production.validation.output_snapshot_ingredient_missing'),
                ]);
            }

            if ($legacyOutputIngredientId !== null && $legacyOutputIngredientId !== (int) $production->output_ingredient_id) {
                throw ValidationException::withMessages([
                    'output_ingredient_id' => __('production_bench.production.validation.output_snapshot_ingredient_fixed'),
                ]);
            }

            return [
                'production_output_type' => $outputType,
                'output_ingredient_id' => (int) $production->output_ingredient_id,
            ];
        }

        if ($legacyOutputIngredientId !== null) {
            throw ValidationException::withMessages([
                'output_ingredient_id' => __('production_bench.production.validation.output_snapshot_finished_fixed'),
            ]);
        }

        return [
            'production_output_type' => $outputType,
            'output_ingredient_id' => null,
        ];
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

        // Validation only; the normalized value is recomputed at completion.
        $this->normalizeOutputQuantity($actualOutputQuantity, $isIntermediate);

        $requirements = $production->requirements()->get();
        $missingWaterActual = ProductionFormulaLine::query()
            ->where('production_run_id', $production->id)
            ->where('component', ProductionFormulaComponent::Water)
            ->where(function ($query): void {
                $query->whereNull('actual_mass_grams')->orWhere('actual_mass_grams', '<=', 0);
            })
            ->exists();

        if ($missingWaterActual) {
            throw ValidationException::withMessages([
                'production' => 'Record a positive actual water quantity before completing.',
            ]);
        }

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

    private function confirmedReadyOn(
        ProductionRun $production,
        string $manufactureDate,
        ?string $estimatedReadyOn,
    ): string {
        if ($estimatedReadyOn !== null) {
            if (! $this->isValidDate($estimatedReadyOn)) {
                throw ValidationException::withMessages([
                    'estimated_ready_on' => __('production_bench.production.validation.estimated_ready_date_invalid'),
                ]);
            }

            return $estimatedReadyOn;
        }

        if ($production->output_ready_delay_days !== null) {
            return Carbon::parse($manufactureDate)
                ->addDays((int) $production->output_ready_delay_days)
                ->toDateString();
        }

        return $this->readyDate($production, $manufactureDate);
    }

    private function assertEligibleConsumptionLot(StockLot $lot, string $manufactureDate): void
    {
        $this->lotPolicy->assertConsumable($lot, Carbon::parse($manufactureDate), 'production');
    }

    private function defaultCalculatedLyeActuals(User $actor, ProductionRun $production): void
    {
        $lyeLines = ProductionFormulaLine::query()
            ->where('production_run_id', $production->id)
            ->whereIn('component', [ProductionFormulaComponent::Naoh, ProductionFormulaComponent::Koh])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($lyeLines as $line) {
            $requirement = ProductionRequirement::query()
                ->where('production_run_id', $production->id)
                ->where('ingredient_id', $line->ingredient_id)
                ->first();

            if (! $requirement instanceof ProductionRequirement) {
                continue;
            }

            $existingLotIds = ProductionConsumption::query()
                ->where('production_run_id', $production->id)
                ->where('production_requirement_id', $requirement->id)
                ->pluck('stock_lot_id')
                ->all();

            StockReservation::query()
                ->where('production_run_id', $production->id)
                ->where('production_requirement_id', $requirement->id)
                ->where('status', StockReservationStatus::Active)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->each(function (StockReservation $reservation) use ($actor, $existingLotIds, $production, $requirement): void {
                    if (in_array($reservation->stock_lot_id, $existingLotIds, true)) {
                        return;
                    }

                    ProductionConsumption::query()->create([
                        'production_run_id' => $production->id,
                        'production_requirement_id' => $requirement->id,
                        'stock_lot_id' => $reservation->stock_lot_id,
                        'kind' => ProductionConsumptionKind::Ingredient,
                        'subject_name_snapshot' => $requirement->subject_name_snapshot,
                        'quantity' => $reservation->quantity,
                        'unit_snapshot' => 'g',
                        'recorded_by_user_id' => $actor->id,
                        'note' => null,
                    ]);
                });
        }
    }

    private function readyDate(ProductionRun $production, string $manufactureDate): ?string
    {
        // Tasks take precedence: the production becomes releasable after the
        // end of the last task. Query directly: the tasks() relation applies
        // its own ascending order.
        $lastTask = ProductionTask::query()
            ->where('production_run_id', $production->id)
            ->orderByDesc('scheduled_for')
            ->orderByDesc('id')
            ->first();

        if ($lastTask !== null) {
            return $lastTask->scheduled_for?->toDateString();
        }

        // Indicative family default: +21 days for soap, +3 days for cosmetics,
        // derived from the snapshotted calculation basis. Never user-entered.
        $calculationBasis = is_array($production->formula_context_snapshot)
            ? ($production->formula_context_snapshot['calculation_basis'] ?? null)
            : null;
        $isCosmetic = $calculationBasis === 'total_formula'
            || ($calculationBasis === null && $production->basis_kind->value === 'total_formula_mass');
        $days = $isCosmetic ? 3 : 21;

        return Carbon::parse($manufactureDate)->addDays($days)->toDateString();
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
