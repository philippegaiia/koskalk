<?php

namespace App\Actions\Production;

use App\Enums\ProductionRunStatus;
use App\Enums\StockLotOrigin;
use App\Enums\StockLotStatus;
use App\Enums\StockMovementType;
use App\Enums\StockReservationStatus;
use App\Enums\StockUnitKind;
use App\Models\ProductionRun;
use App\Models\ProductSize;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Production\ConsumableStockLotPolicy;
use App\Services\Production\PackedLotCodeGenerator;
use App\Services\Production\ProductionEditingContext;
use App\Services\Production\ProductionMutationResult;
use App\Services\Production\ProductionRequirementBuilder;
use App\Services\ProductionBenchAccess;
use App\Services\ProductionMutationGuard;
use App\Services\ProductSizeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PackageBulkProduction
{
    public function __construct(
        private readonly ProductionBenchAccess $access,
        private readonly ProductionMutationGuard $guard,
        private readonly ConsumableStockLotPolicy $lotPolicy,
        private readonly ProductSizeResolver $sizes,
        private readonly ProductionRequirementBuilder $requirements,
        private readonly PackedLotCodeGenerator $lotCodes,
    ) {}

    public function handle(
        User $actor,
        ProductionRun $production,
        string $sizeKey,
        int $units,
        string $bulkGrams,
        string $requestKey,
        ProductionEditingContext $editing,
        ?string $lotCode = null,
    ): StockLot {
        $this->access->assertWritable($actor, $production->workspace);
        $lotCode = trim($lotCode ?? '') === '' ? null : trim($lotCode);
        if ($lotCode !== null && ! $this->lotCodes->isWellFormed($lotCode)) {
            throw ValidationException::withMessages(['packedLotCode' => __('production_bench.packaging.validation.lot_code_invalid')]);
        }
        validator(['packagedUnits' => $units, 'packedBulkGrams' => $bulkGrams, 'requestKey' => $requestKey], [
            'packagedUnits' => ['integer', 'min:1', 'max:2147483647'],
            'packedBulkGrams' => ['required', 'numeric', 'regex:/^\d{1,11}(?:\.\d{1,9})?$/D', 'gt:0'],
            'requestKey' => ['required', 'uuid'],
        ])->validate();

        return $this->guard->run($actor, [$production->id], $editing, function (
            User $actor,
            Workspace $workspace,
            Collection $productions,
        ) use ($production, $sizeKey, $units, $bulkGrams, $requestKey, $lotCode): ProductionMutationResult {
            $this->access->assertWritable($actor, $workspace);
            $run = $productions[$production->id];
            $bulk = $run->outputLot()->where('workspace_id', $workspace->id)
                ->withSum('movements', 'quantity_delta')
                ->withCasts(['movements_sum_quantity_delta' => 'decimal:9'])
                ->lockForUpdate()->first();
            $version = $run->recipeVersion;

            if (! $run->package_later || $run->status !== ProductionRunStatus::Completed || $run->batch_number === null
                || ! $bulk instanceof StockLot || $bulk->unit_kind !== StockUnitKind::Mass
                || $bulk->recipe_id !== $run->recipe_id || $version === null) {
                throw ValidationException::withMessages(['packing' => __('production_bench.packaging.bulk_unavailable')]);
            }
            $size = $this->sizes->forVersion($version, preferPackaged: true)->firstWhere('public_id', strtolower($sizeKey));
            if (! $size instanceof ProductSize) {
                throw ValidationException::withMessages(['packedSizeKey' => __('batch_log.validation.invalid_size')]);
            }

            $request = StockMovement::query()->where('workspace_id', $workspace->id)
                ->where('idempotency_key', $requestKey)->first();
            if ($request !== null) {
                $existing = $run->packagedLots()->find($request->stock_lot_id);
                $sourceMovement = $bulk->movements()->where('source_type', (new StockLot)->getMorphClass())
                    ->where('source_id', $existing?->id)->where('type', StockMovementType::PackagingConsumption)->first();
                if ($request->type !== StockMovementType::PackagingOutput || $existing === null || $existing->product_size_id !== $size->id
                    || bccomp($request->quantity_delta, (string) $units, 9) !== 0 || $sourceMovement === null
                    || bccomp($sourceMovement->original_quantity, $bulkGrams, 9) !== 0
                    || ($lotCode !== null && strcasecmp($existing->internal_lot_code, $lotCode) !== 0)) {
                    throw ValidationException::withMessages(['packing' => __('production_bench.production.validation.create_idempotency_conflict')]);
                }

                return new ProductionMutationResult($existing, []);
            }

            $this->lotPolicy->assertConsumable($bulk, today(), 'packing');
            if ($bulk->stocked_at->isAfter(today())) {
                throw ValidationException::withMessages(['packing' => __('production_bench.production.validation.stock_lot_not_available')]);
            }
            if (bccomp($bulkGrams, $bulk->movements_sum_quantity_delta ?? '0', 9) > 0) {
                throw ValidationException::withMessages(['packedBulkGrams' => __('production_bench.packaging.bulk_shortage')]);
            }

            if ($lotCode !== null && $this->lotCodes->isTaken($workspace, $lotCode)) {
                throw ValidationException::withMessages(['packedLotCode' => __('production_bench.packaging.validation.lot_code_taken')]);
            }

            if ($lotCode !== null) {
                $nextBatchNumber = $this->lotCodes->nextBatchNumber($workspace);
                if (strcasecmp($lotCode, $nextBatchNumber) === 0) {
                    throw ValidationException::withMessages([
                        'packedLotCode' => __('production_bench.packaging.validation.lot_code_next_batch', [
                            'batch' => $nextBatchNumber,
                            'suggestion' => $this->lotCodes->suggest($workspace, $run->batch_number, $size),
                        ]),
                    ]);
                }
            }

            $sizeSelection = $this->sizes->selectForProduction($version, $sizeKey);
            $components = $this->requirements->linkedBulkPackaging($version, $sizeSelection, 'packing')->groupBy('packaging_item_id');
            $draws = [[$bulk, $bulkGrams, 'g']];
            foreach ($components as $itemId => $lines) {
                $required = $lines->reduce(fn (string $total, $line): string => bcadd(
                    $total, bcceil(bcmul((string) $units, $line->components_per_unit, 9)), 0,
                ), '0');
                foreach ($this->packagingDraws($workspace, (int) $itemId, $required) as $draw) {
                    $draws[] = $draw;
                }
            }

            [$unitCost, $currency] = $this->cost($workspace, $draws, $units);
            $lot = $run->packagedLots()->create([
                'workspace_id' => $workspace->id,
                'recipe_id' => $run->recipe_id,
                'product_size_id' => $size->id,
                'product_size_label_snapshot' => $size->label($actor->number_locale),
                'product_size_content_value_snapshot' => $size->declared_content_value,
                'product_size_content_unit_snapshot' => $size->declared_content_unit?->value,
                'internal_lot_code' => $lotCode ?? $this->lotCodes->suggest($workspace, $run->batch_number, $size),
                'origin' => StockLotOrigin::PackagingOutput,
                'unit_kind' => StockUnitKind::Count,
                'status' => StockLotStatus::Released,
                'stocked_at' => today(),
                'expires_at' => $bulk->expires_at,
                'available_from' => today(),
                'estimated_ready_on' => $bulk->estimated_ready_on,
                'released_at' => now(),
                'released_by_user_id' => $actor->id,
                'provenance_complete' => $bulk->provenance_complete && ! collect($draws)->contains(fn (array $draw): bool => ! $draw[0]->provenance_complete),
                'historical_unit_cost' => $unitCost,
                'costing_unit_cost' => $unitCost,
                'costing_currency' => $currency,
                'currency' => $currency,
            ]);
            foreach ($draws as [$source, $quantity, $unit]) {
                $this->postMovement($actor, $source, $lot, StockMovementType::PackagingConsumption, $quantity, $unit, (string) Str::uuid());
            }
            $this->postMovement($actor, $lot, $lot, StockMovementType::PackagingOutput, (string) $units, 'unit', $requestKey);

            return new ProductionMutationResult($lot, [$run->id]);
        });
    }

    /** @return list<array{StockLot, string, string}> */
    private function packagingDraws(Workspace $workspace, int $itemId, string $required): array
    {
        $date = today()->toDateString();
        $lots = StockLot::query()->where('workspace_id', $workspace->id)
            ->where('packaging_item_id', $itemId)
            ->where('status', StockLotStatus::Released)
            ->where(fn (Builder $query): Builder => $query->whereNull('available_from')->orWhereDate('available_from', '<=', $date))
            ->where(fn (Builder $query): Builder => $query->whereNull('expires_at')->orWhereDate('expires_at', '>=', $date))
            ->withSum('movements', 'quantity_delta')
            ->withSum([
                'reservations as active_reserved_quantity' => fn (Builder $query): Builder => $query->where('status', StockReservationStatus::Active),
            ], 'quantity')
            ->withCasts(['movements_sum_quantity_delta' => 'decimal:9', 'active_reserved_quantity' => 'decimal:9'])
            ->orderByRaw('expires_at IS NULL')->orderBy('expires_at')->orderBy('stocked_at')->orderBy('id')
            ->lockForUpdate()->get();
        $draws = [];
        foreach ($lots as $lot) {
            if (bccomp($required, '0', 9) <= 0) {
                break;
            }

            $available = bcfloor(bcsub($lot->movements_sum_quantity_delta ?? '0', $lot->active_reserved_quantity ?? '0', 9));
            if (bccomp($available, '0', 9) <= 0) {
                continue;
            }

            $amount = bccomp($available, $required, 9) >= 0 ? $required : $available;
            $draws[] = [$lot, $amount, 'unit'];
            $required = bcsub($required, $amount, 0);
        }
        if (bccomp($required, '0', 9) > 0) {
            throw ValidationException::withMessages(['packing' => __('production_bench.packaging.material_shortage')]);
        }

        return $draws;
    }

    /** @param list<array{StockLot, string, string}> $draws
     * @return array{?string, ?string}
     */
    private function cost(Workspace $workspace, array $draws, int $units): array
    {
        $total = '0';
        $currency = null;
        foreach ($draws as [$lot, $quantity]) {
            $price = $lot->costing_unit_cost ?? $lot->historical_unit_cost;
            $lineCurrency = $lot->costing_unit_cost !== null ? ($lot->costing_currency ?? $workspace->default_currency) : $lot->currency;
            if ($price === null || blank($lineCurrency) || ($currency !== null && $currency !== $lineCurrency)) {
                return [null, null];
            }
            $total = bcadd($total, bcmul($price, $quantity, 9), 9);
            $currency = $lineCurrency;
        }

        return [bcdiv($total, (string) $units, 9), $currency];
    }

    private function postMovement(User $actor, StockLot $source, StockLot $output, StockMovementType $type, string $quantity, string $unit, string $key): void
    {
        $source->movements()->create([
            'workspace_id' => $output->workspace_id,
            'type' => $type,
            'quantity_delta' => $type === StockMovementType::PackagingOutput ? $quantity : '-'.$quantity,
            'original_quantity' => $quantity,
            'original_unit' => $unit,
            'occurred_at' => now(),
            'actor_user_id' => $actor->id,
            'source_type' => $output->getMorphClass(),
            'source_id' => $output->id,
            'idempotency_key' => $key,
        ]);
    }
}
