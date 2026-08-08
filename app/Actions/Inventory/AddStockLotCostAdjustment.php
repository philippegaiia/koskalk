<?php

namespace App\Actions\Inventory;

use App\Data\ExchangeRateSnapshot;
use App\Enums\MaterialPriceSource;
use App\Enums\StockLotCostAdjustmentType;
use App\Models\GoodsReceiptLine;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\StockLot;
use App\Models\StockLotCostAdjustment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CurrentMaterialPriceService;
use App\Services\ExchangeRateService;
use App\Services\ProductionBenchAccess;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class AddStockLotCostAdjustment
{
    public function __construct(
        private readonly ProductionBenchAccess $access,
        private readonly ExchangeRateService $exchangeRateService,
        private readonly CurrentMaterialPriceService $currentMaterialPriceService,
    ) {}

    public function handle(
        User $actor,
        Workspace $workspace,
        StockLot $lot,
        StockLotCostAdjustmentType $type,
        string $amount,
        string $currency,
        string $reason,
        ?string $manualRate = null,
        ?int $compensatesAdjustmentId = null,
    ): StockLotCostAdjustment {
        $normalizedAmount = $this->validatedAmount($amount);
        $normalizedReason = trim($reason);

        if ($normalizedReason === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required for every cost adjustment.']);
        }

        $this->access->assertWritable($actor, $workspace);

        return DB::transaction(function () use (
            $actor,
            $workspace,
            $lot,
            $type,
            $normalizedAmount,
            $currency,
            $normalizedReason,
            $manualRate,
            $compensatesAdjustmentId,
        ): StockLotCostAdjustment {
            $lockedWorkspace = Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($workspace->id);
            $this->access->assertWritable($actor, $lockedWorkspace);
            $lockedLot = StockLot::query()
                ->with(['goodsReceiptLine', 'costAdjustments'])
                ->lockForUpdate()
                ->findOrFail($lot->id);

            if ($lockedLot->workspace_id !== $lockedWorkspace->id) {
                throw ValidationException::withMessages([
                    'lot' => 'The stock lot must belong to the active workspace.',
                ]);
            }

            if (! $lockedLot->goodsReceiptLine instanceof GoodsReceiptLine) {
                throw ValidationException::withMessages([
                    'lot' => 'Only receipt lots can receive acquisition cost adjustments.',
                ]);
            }

            if ($compensatesAdjustmentId !== null && StockLotCostAdjustment::query()
                ->whereKey($compensatesAdjustmentId)
                ->where('workspace_id', $lockedWorkspace->id)
                ->where('stock_lot_id', $lockedLot->id)
                ->doesntExist()) {
                throw ValidationException::withMessages([
                    'compensates_adjustment_id' => 'The adjustment being compensated must belong to this stock lot.',
                ]);
            }

            if ($compensatesAdjustmentId !== null && StockLotCostAdjustment::query()
                ->where('workspace_id', $lockedWorkspace->id)
                ->where('stock_lot_id', $lockedLot->id)
                ->where('compensates_adjustment_id', $compensatesAdjustmentId)
                ->exists()) {
                throw ValidationException::withMessages([
                    'compensates_adjustment_id' => 'This adjustment has already been compensated.',
                ]);
            }

            $rateSnapshot = $this->rateSnapshot(
                currency: $currency,
                costingCurrency: $lockedWorkspace->default_currency,
                date: now(),
                manualRate: $manualRate,
            );
            $costingAmount = bcmul($normalizedAmount, $rateSnapshot->rate, 9);

            $adjustment = $lockedLot->costAdjustments()->create([
                'workspace_id' => $lockedWorkspace->id,
                'stock_lot_id' => $lockedLot->id,
                'compensates_adjustment_id' => $compensatesAdjustmentId,
                'type' => $type,
                'amount' => $normalizedAmount,
                'currency' => strtoupper(trim($currency)),
                'costing_amount' => $costingAmount,
                'costing_currency' => $rateSnapshot->quoteCurrency,
                'exchange_rate' => $rateSnapshot->rate,
                'exchange_rate_date' => $rateSnapshot->rateDate,
                'exchange_rate_provider' => $rateSnapshot->provider,
                'exchange_rate_is_manual' => $rateSnapshot->isManual,
                'reason' => $normalizedReason,
                'created_by_user_id' => $actor->id,
            ]);

            $lockedLot->load(['goodsReceiptLine', 'costAdjustments']);

            if (bccomp($lockedLot->effectiveCostingTotalCost(), '0', 9) <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'The adjustment cannot reduce the lot cost to zero or below.',
                ]);
            }

            $this->rememberEffectivePrice($lockedWorkspace, $lockedLot, $actor);

            return $adjustment;
        }, attempts: 5);
    }

    public function compensate(
        User $actor,
        Workspace $workspace,
        StockLotCostAdjustment $adjustment,
        string $reason,
    ): StockLotCostAdjustment {
        if ($adjustment->workspace_id !== $workspace->id) {
            throw ValidationException::withMessages([
                'adjustment' => 'The adjustment must belong to the active workspace.',
            ]);
        }

        return $this->handle(
            actor: $actor,
            workspace: $workspace,
            lot: $adjustment->stockLot,
            type: $adjustment->type,
            amount: bcsub('0', (string) $adjustment->amount, 9),
            currency: $adjustment->currency,
            reason: $reason,
            manualRate: (string) $adjustment->exchange_rate,
            compensatesAdjustmentId: $adjustment->id,
        );
    }

    private function rememberEffectivePrice(Workspace $workspace, StockLot $lot, User $actor): void
    {
        $price = $lot->effectiveCostingUnitCost();
        $currency = (string) ($lot->costing_currency ?? $workspace->default_currency);

        if ($lot->ingredient_id !== null) {
            $ingredient = Ingredient::withoutGlobalScopes()->findOrFail($lot->ingredient_id);
            $this->currentMaterialPriceService->rememberIngredient(
                workspace: $workspace,
                ingredient: $ingredient,
                pricePerMassUnit: $price,
                massUnit: 'g',
                currency: $currency,
                source: MaterialPriceSource::Receipt,
                sourceId: $lot->id,
                actor: $actor,
            );

            return;
        }

        $packagingItem = PackagingItem::query()->findOrFail($lot->packaging_item_id);
        $this->currentMaterialPriceService->rememberPackaging(
            workspace: $workspace,
            packagingItem: $packagingItem,
            pricePerItem: $price,
            currency: $currency,
            source: MaterialPriceSource::Receipt,
            sourceId: $lot->id,
            actor: $actor,
        );
    }

    private function rateSnapshot(
        string $currency,
        string $costingCurrency,
        CarbonInterface $date,
        ?string $manualRate,
    ): ExchangeRateSnapshot {
        try {
            return $this->exchangeRateService->snapshot(
                baseCurrency: $currency,
                quoteCurrency: $costingCurrency,
                date: $date->toDateString(),
                manualRate: $manualRate,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['currency' => $exception->getMessage()]);
        }
    }

    private function validatedAmount(string $amount): string
    {
        $amount = trim($amount);

        if (preg_match('/^-?\d+(?:\.\d+)?$/', $amount) !== 1 || bccomp($amount, '0', 9) === 0) {
            throw ValidationException::withMessages([
                'amount' => 'The adjustment must be a non-zero decimal value.',
            ]);
        }

        return bcadd($amount, '0', 9);
    }
}
