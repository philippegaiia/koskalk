<?php

namespace App\Actions\Purchasing;

use App\Enums\GoodsReceiptSource;
use App\Enums\GoodsReceiptStatus;
use App\Enums\ListingPriceBasis;
use App\Enums\MaterialPriceSource;
use App\Enums\OwnerType;
use App\Enums\StockLotOrigin;
use App\Enums\StockLotStatus;
use App\Enums\StockMovementType;
use App\Enums\StockUnitKind;
use App\Models\CurrentMaterialPrice;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\PurchaseOrderLine;
use App\Models\StockLot;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CurrentMaterialPriceService;
use App\Services\ExchangeRateService;
use App\Services\InternalLotCodeGenerator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PostGoodsReceiptLine
{
    public function __construct(
        private readonly InternalLotCodeGenerator $lotCodeGenerator,
        private readonly CurrentMaterialPriceService $currentMaterialPriceService,
        private readonly ExchangeRateService $exchangeRateService,
    ) {}

    public function handle(
        User $actor,
        Workspace $workspace,
        GoodsReceipt $receipt,
        SupplierListing $listing,
        ?PurchaseOrderLine $purchaseOrderLine,
        int $packsReceived,
        string $actualQuantity,
        string $originalQuantity,
        string $originalUnit,
        ListingPriceBasis $receiptPriceBasis,
        string $receiptPriceAmount,
        ?string $receiptPriceUnit,
        string $purchaseFormatPrice,
        string $currency,
        string $movementIdempotencyKey,
        ?string $manualRate = null,
        ?string $supplierBatchNumber = null,
        ?string $expiresAt = null,
        ?string $notes = null,
    ): GoodsReceiptLine {
        $this->assertCoherent(
            $actor,
            $workspace,
            $receipt,
            $listing,
            $purchaseOrderLine,
            $packsReceived,
            $actualQuantity,
            $currency,
            $movementIdempotencyKey,
        );
        $stockedAt = $receipt->received_at->toDateString();
        $ingredientId = $purchaseOrderLine instanceof PurchaseOrderLine
            ? $purchaseOrderLine->ingredient_id
            : $listing->ingredient_id;
        $packagingItemId = $purchaseOrderLine instanceof PurchaseOrderLine
            ? $purchaseOrderLine->packaging_item_id
            : $listing->packaging_item_id;
        $organicStatus = $purchaseOrderLine instanceof PurchaseOrderLine
            ? $purchaseOrderLine->organic_status
            : $listing->organic_status;
        $unitKind = $purchaseOrderLine instanceof PurchaseOrderLine
            ? $purchaseOrderLine->unit_kind
            : $listing->unit_kind;
        $previousMaterialPrice = CurrentMaterialPrice::query()
            ->where('workspace_id', $workspace->id)
            ->where($ingredientId === null
                ? ['packaging_item_id' => $packagingItemId]
                : ['ingredient_id' => $ingredientId])
            ->lockForUpdate()
            ->first();
        $previousMaterialPriceSnapshot = $previousMaterialPrice instanceof CurrentMaterialPrice
            ? [
                'price_per_canonical_unit' => $previousMaterialPrice->price_per_canonical_unit,
                'currency' => $previousMaterialPrice->currency,
                'recorded_at' => $previousMaterialPrice->recorded_at->toISOString(),
                'source_type' => $previousMaterialPrice->source_type->value,
                'source_id' => $previousMaterialPrice->source_id,
                'created_by_user_id' => $previousMaterialPrice->created_by_user_id,
            ]
            : null;
        $historicalTotalCost = bcmul($purchaseFormatPrice, (string) $packsReceived, 9);
        $historicalUnitCost = bcround(bcdiv($historicalTotalCost, $actualQuantity, 18), 9);
        if (bccomp($historicalUnitCost, '0', 9) <= 0) {
            throw ValidationException::withMessages([
                'receipt_price_amount' => __('production_bench.receipt.price_too_small'),
            ]);
        }

        try {
            $exchangeRate = $this->exchangeRateService->snapshot(
                baseCurrency: $currency,
                quoteCurrency: $workspace->default_currency,
                date: $stockedAt,
                manualRate: $manualRate,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'exchange_rate' => blank($manualRate)
                    ? __('production_bench.receipt.auto_rate_unavailable', [
                        'base' => strtoupper($currency),
                        'quote' => strtoupper($workspace->default_currency),
                    ])
                    : $exception->getMessage(),
            ]);
        }

        $costingTotalCost = bcmul($historicalTotalCost, $exchangeRate->rate, 9);
        $costingUnitCost = bcround(bcdiv($costingTotalCost, $actualQuantity, 18), 9);

        $lot = StockLot::query()->create([
            'workspace_id' => $workspace->id,
            'supplier_listing_id' => $listing->id,
            'ingredient_id' => $ingredientId,
            'packaging_item_id' => $packagingItemId,
            'organic_status' => $organicStatus,
            'internal_lot_code' => $this->lotCodeGenerator->next($workspace),
            'supplier_batch_number' => $supplierBatchNumber,
            'origin' => StockLotOrigin::PurchaseReceipt,
            'unit_kind' => $unitKind,
            'status' => StockLotStatus::Released,
            'stocked_at' => $stockedAt,
            'expires_at' => $expiresAt,
            'available_from' => $stockedAt,
            'released_at' => now(),
            'released_by_user_id' => $actor->id,
            'provenance_complete' => filled($supplierBatchNumber),
            'historical_unit_cost' => $historicalUnitCost,
            'currency' => $currency,
            'costing_unit_cost' => $costingUnitCost,
            'costing_currency' => $exchangeRate->quoteCurrency,
            'exchange_rate' => $exchangeRate->rate,
            'exchange_rate_date' => $exchangeRate->rateDate,
            'exchange_rate_provider' => $exchangeRate->provider,
            'exchange_rate_is_manual' => $exchangeRate->isManual,
            'notes' => $notes,
        ]);

        $receiptLine = $receipt->lines()->create([
            'purchase_order_line_id' => $purchaseOrderLine?->id,
            'supplier_listing_id' => $listing->id,
            'stock_lot_id' => $lot->id,
            'packs_received' => $packsReceived,
            'actual_quantity' => $actualQuantity,
            'original_quantity' => $originalQuantity,
            'original_unit' => $originalUnit,
            'historical_total_cost' => $historicalTotalCost,
            'costing_total_cost' => $costingTotalCost,
            'receipt_price_basis' => $receiptPriceBasis,
            'receipt_price_amount' => $receiptPriceAmount,
            'receipt_price_unit' => $receiptPriceUnit,
            'purchase_format_price' => $purchaseFormatPrice,
            'currency' => $currency,
            'costing_currency' => $exchangeRate->quoteCurrency,
            'exchange_rate' => $exchangeRate->rate,
            'exchange_rate_date' => $exchangeRate->rateDate,
            'exchange_rate_provider' => $exchangeRate->provider,
            'exchange_rate_is_manual' => $exchangeRate->isManual,
            'supplier_batch_number' => $supplierBatchNumber,
            'expires_at' => $expiresAt,
            'notes' => $notes,
            'previous_material_price_snapshot' => $previousMaterialPriceSnapshot,
        ]);

        $lot->movements()->create([
            'workspace_id' => $workspace->id,
            'type' => StockMovementType::PurchaseReceipt,
            'quantity_delta' => $actualQuantity,
            'original_quantity' => $originalQuantity,
            'original_unit' => $originalUnit,
            'occurred_at' => now(),
            'actor_user_id' => $actor->id,
            'source_type' => $receiptLine->getMorphClass(),
            'source_id' => $receiptLine->id,
            'idempotency_key' => $movementIdempotencyKey,
        ]);

        if ($ingredientId !== null) {
            $ingredient = Ingredient::withoutGlobalScopes()->findOrFail($ingredientId);
            $this->currentMaterialPriceService->rememberIngredient(
                workspace: $workspace,
                ingredient: $ingredient,
                pricePerMassUnit: $lot->costing_unit_cost,
                massUnit: 'g',
                currency: $lot->costing_currency,
                source: MaterialPriceSource::Receipt,
                sourceId: $lot->id,
                actor: $actor,
            );
        } else {
            $packagingItem = PackagingItem::query()->findOrFail($packagingItemId);
            $this->currentMaterialPriceService->rememberPackaging(
                workspace: $workspace,
                packagingItem: $packagingItem,
                pricePerItem: $lot->costing_unit_cost,
                currency: $lot->costing_currency,
                source: MaterialPriceSource::Receipt,
                sourceId: $lot->id,
                actor: $actor,
            );
        }

        return $receiptLine->setRelation('stockLot', $lot);
    }

    private function assertCoherent(
        User $actor,
        Workspace $workspace,
        GoodsReceipt $receipt,
        SupplierListing $listing,
        ?PurchaseOrderLine $purchaseOrderLine,
        int $packsReceived,
        string $actualQuantity,
        string $currency,
        string $movementIdempotencyKey,
    ): void {
        $isPurchaseOrderReceipt = $purchaseOrderLine instanceof PurchaseOrderLine;
        $ingredientId = $isPurchaseOrderReceipt ? $purchaseOrderLine->ingredient_id : $listing->ingredient_id;
        $packagingItemId = $isPurchaseOrderReceipt ? $purchaseOrderLine->packaging_item_id : $listing->packaging_item_id;
        $unitKind = $isPurchaseOrderReceipt ? $purchaseOrderLine->unit_kind : $listing->unit_kind;
        $expectedCurrency = $isPurchaseOrderReceipt ? $purchaseOrderLine->currency : $listing->currency;

        if (
            $receipt->workspace_id !== $workspace->id
            || $listing->workspace_id !== $workspace->id
            || $receipt->supplier_id !== $listing->supplier_id
            || $receipt->status !== GoodsReceiptStatus::Posted
            || ! $workspace->hasMember($actor)
            || $packsReceived < 1
            || bccomp($actualQuantity, '0', 9) <= 0
            || strtoupper($currency) !== strtoupper($expectedCurrency)
            || blank($movementIdempotencyKey)
            || mb_strlen($movementIdempotencyKey) > 120
            || (($ingredientId === null) === ($packagingItemId === null))
            || ($unitKind === StockUnitKind::Mass && $ingredientId === null)
            || ($unitKind === StockUnitKind::Count && $packagingItemId === null)
        ) {
            throw ValidationException::withMessages([
                'receipt_line' => __('production_bench.receipt.line_incoherent'),
            ]);
        }

        if ($isPurchaseOrderReceipt) {
            $purchaseOrder = $purchaseOrderLine->purchaseOrder;

            if (
                $receipt->source !== GoodsReceiptSource::PurchaseOrder
                || $receipt->purchase_order_id !== $purchaseOrderLine->purchase_order_id
                || $purchaseOrder->workspace_id !== $workspace->id
                || $purchaseOrder->supplier_id !== $receipt->supplier_id
                || $purchaseOrderLine->supplier_listing_id !== $listing->id
                || $purchaseOrderLine->ingredient_id !== $listing->ingredient_id
                || $purchaseOrderLine->packaging_item_id !== $listing->packaging_item_id
                || $purchaseOrderLine->unit_kind !== $listing->unit_kind
            ) {
                throw ValidationException::withMessages([
                    'receipt_line' => __('production_bench.receipt.ordered_line_incoherent'),
                ]);
            }
        } elseif ($receipt->source !== GoodsReceiptSource::Direct || $receipt->purchase_order_id !== null) {
            throw ValidationException::withMessages([
                'receipt_line' => __('production_bench.receipt.direct_line_order_reference'),
            ]);
        }

        if ($ingredientId !== null) {
            $ingredient = Ingredient::withoutGlobalScopes()->findOrFail($ingredientId);
            $ingredientWorkspaceId = $ingredient->tenantWorkspaceId();

            if ($ingredientWorkspaceId === null && $ingredient->tenantOwnerType() === OwnerType::Workspace) {
                $ingredientWorkspaceId = $ingredient->tenantOwnerId();
            }

            if (
                ! $ingredient->isAccessibleBy($actor)
                || (
                    ! $ingredient->isPublicCatalog()
                    && $ingredientWorkspaceId !== null
                    && $ingredientWorkspaceId !== $workspace->id
                )
            ) {
                throw ValidationException::withMessages([
                    'receipt_line' => __('production_bench.receipt.receipt_ingredient_inaccessible'),
                ]);
            }

            return;
        }

        if (PackagingItem::query()->whereKey($packagingItemId)->where('workspace_id', $workspace->id)->doesntExist()) {
            throw ValidationException::withMessages([
                'receipt_line' => __('production_bench.receipt.receipt_packaging_workspace'),
            ]);
        }
    }
}
