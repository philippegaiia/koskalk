<?php

namespace App\Actions\Purchasing;

use App\GoodsReceiptStatus;
use App\MaterialPriceSource;
use App\Models\CurrentMaterialPrice;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\StockLot;
use App\Models\SupplierListing;
use App\Models\User;
use App\PurchaseOrderStatus;
use App\Services\CurrentMaterialPriceService;
use App\StockLotOrigin;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

class RebuildCurrentMaterialPriceAfterReceiptReversal
{
    public function __construct(private readonly CurrentMaterialPriceService $currentMaterialPriceService) {}

    public function handle(User $actor, GoodsReceipt $receipt): void
    {
        $workspace = $receipt->workspace;
        $lines = $receipt->lines()->with('stockLot')->get();
        $reversedLotIds = $lines->pluck('stock_lot_id')->all();
        $processedSubjects = [];

        foreach ($lines as $line) {
            $lot = $line->stockLot;
            $subject = $lot->ingredient_id === null
                ? ['packaging_item_id' => $lot->packaging_item_id]
                : ['ingredient_id' => $lot->ingredient_id];
            $subjectKey = array_key_first($subject).':'.reset($subject);

            if (isset($processedSubjects[$subjectKey])) {
                continue;
            }

            $processedSubjects[$subjectKey] = true;
            $currentPrice = CurrentMaterialPrice::query()
                ->where('workspace_id', $workspace->id)
                ->where($subject)
                ->lockForUpdate()
                ->first();

            if (
                ! $currentPrice instanceof CurrentMaterialPrice
                || $currentPrice->source_type !== MaterialPriceSource::Receipt
                || ! in_array($currentPrice->source_id, $reversedLotIds, true)
            ) {
                continue;
            }

            $candidate = $this->newestSnapshotCandidate(
                $lines,
                $workspace->id,
                $lot->ingredient_id,
                $lot->packaging_item_id,
                $reversedLotIds,
            ) ?? $this->newestCandidate(
                $workspace->id,
                $lot->ingredient_id,
                $lot->packaging_item_id,
            );

            if ($lot->ingredient_id !== null) {
                $ingredient = Ingredient::withoutGlobalScopes()->findOrFail($lot->ingredient_id);
                $this->currentMaterialPriceService->restoreIngredientProjection(
                    workspace: $workspace,
                    ingredient: $ingredient,
                    canonicalPrice: $candidate['price'] ?? null,
                    currency: $candidate['currency'] ?? null,
                    source: $candidate['source'] ?? null,
                    sourceId: $candidate['source_id'] ?? null,
                    recordedAt: $candidate['recorded_at'] ?? null,
                    actor: $actor,
                    createdByUserId: $candidate['created_by_user_id'] ?? null,
                );

                continue;
            }

            $packagingItem = PackagingItem::query()->findOrFail($lot->packaging_item_id);
            $this->currentMaterialPriceService->restorePackagingProjection(
                workspace: $workspace,
                packagingItem: $packagingItem,
                canonicalPrice: $candidate['price'] ?? null,
                currency: $candidate['currency'] ?? null,
                source: $candidate['source'] ?? null,
                sourceId: $candidate['source_id'] ?? null,
                recordedAt: $candidate['recorded_at'] ?? null,
                actor: $actor,
                createdByUserId: $candidate['created_by_user_id'] ?? null,
            );
        }
    }

    /**
     * @param  Collection<int, GoodsReceiptLine>  $lines
     * @param  array<int, int>  $reversedLotIds
     * @return array{price: string, currency: string, source: MaterialPriceSource, source_id: int|null, recorded_at: CarbonInterface, created_by_user_id: int|null}|null
     */
    private function newestSnapshotCandidate(
        Collection $lines,
        int $workspaceId,
        ?int $ingredientId,
        ?int $packagingItemId,
        array $reversedLotIds,
    ): ?array {
        $candidates = [];

        foreach ($lines as $line) {
            if (
                $line->stockLot->ingredient_id !== $ingredientId
                || $line->stockLot->packaging_item_id !== $packagingItemId
            ) {
                continue;
            }

            $snapshot = $line->previous_material_price_snapshot;

            if (! is_array($snapshot)) {
                continue;
            }

            $source = isset($snapshot['source_type']) && is_string($snapshot['source_type'])
                ? MaterialPriceSource::tryFrom($snapshot['source_type'])
                : null;
            $sourceId = isset($snapshot['source_id']) ? (int) $snapshot['source_id'] : null;

            try {
                $recordedAt = isset($snapshot['recorded_at']) && is_string($snapshot['recorded_at'])
                    ? CarbonImmutable::parse($snapshot['recorded_at'])
                    : null;
            } catch (Throwable) {
                $recordedAt = null;
            }

            if (
                ! $source instanceof MaterialPriceSource
                || ! $recordedAt instanceof CarbonInterface
                || ! isset($snapshot['price_per_canonical_unit'], $snapshot['currency'])
                || ! is_string($snapshot['price_per_canonical_unit'])
                || ! is_string($snapshot['currency'])
                || ! $this->snapshotSourceIsValid(
                    $source,
                    $sourceId,
                    $workspaceId,
                    $ingredientId,
                    $packagingItemId,
                    $reversedLotIds,
                )
            ) {
                continue;
            }

            $candidates[] = [
                'price' => $snapshot['price_per_canonical_unit'],
                'currency' => $snapshot['currency'],
                'source' => $source,
                'source_id' => $sourceId,
                'recorded_at' => $recordedAt,
                'created_by_user_id' => isset($snapshot['created_by_user_id'])
                    ? (int) $snapshot['created_by_user_id']
                    : null,
                'stable_id' => $line->id,
            ];
        }

        usort($candidates, fn (array $left, array $right): int => ($right['recorded_at']->getTimestamp() <=> $left['recorded_at']->getTimestamp())
            ?: ($right['stable_id'] <=> $left['stable_id']));

        return $candidates[0] ?? null;
    }

    /** @param array<int, int> $reversedLotIds */
    private function snapshotSourceIsValid(
        MaterialPriceSource $source,
        ?int $sourceId,
        int $workspaceId,
        ?int $ingredientId,
        ?int $packagingItemId,
        array $reversedLotIds,
    ): bool {
        $subject = $ingredientId === null
            ? ['packaging_item_id' => $packagingItemId]
            : ['ingredient_id' => $ingredientId];

        return match ($source) {
            MaterialPriceSource::ManualCosting => true,
            MaterialPriceSource::OpeningStock => $sourceId !== null
                && StockLot::query()
                    ->where('workspace_id', $workspaceId)
                    ->where($subject)
                    ->where('origin', StockLotOrigin::OpeningBalance)
                    ->whereKey($sourceId)
                    ->exists(),
            MaterialPriceSource::Receipt => $sourceId !== null
                && ! in_array($sourceId, $reversedLotIds, true)
                && GoodsReceiptLine::query()
                    ->where('stock_lot_id', $sourceId)
                    ->whereHas('goodsReceipt', fn (Builder $query): Builder => $query
                        ->where('workspace_id', $workspaceId)
                        ->where('status', GoodsReceiptStatus::Posted))
                    ->whereHas('stockLot', fn (Builder $query): Builder => $query->where($subject))
                    ->exists(),
            MaterialPriceSource::ProcurementDocument => $sourceId !== null
                && PurchaseOrder::query()
                    ->where('workspace_id', $workspaceId)
                    ->whereNotNull('issued_at')
                    ->whereIn('status', $this->priceBearingOrderStatuses())
                    ->whereKey($sourceId)
                    ->exists(),
            MaterialPriceSource::SupplierListing => $sourceId !== null
                && SupplierListing::query()
                    ->where('workspace_id', $workspaceId)
                    ->where($subject)
                    ->where('is_active', true)
                    ->whereKey($sourceId)
                    ->exists(),
        };
    }

    /**
     * @return array{price: string, currency: string, source: MaterialPriceSource, source_id: int, recorded_at: CarbonInterface, created_by_user_id: null, priority: int, stable_id: string}|null
     */
    private function newestCandidate(int $workspaceId, ?int $ingredientId, ?int $packagingItemId): ?array
    {
        $candidates = [];
        $subjectColumn = $ingredientId === null ? 'packaging_item_id' : 'ingredient_id';
        $subjectId = $ingredientId ?? $packagingItemId;

        $receiptLines = GoodsReceiptLine::query()
            ->whereHas('goodsReceipt', fn (Builder $query): Builder => $query
                ->where('workspace_id', $workspaceId)
                ->where('status', GoodsReceiptStatus::Posted))
            ->whereHas('stockLot', fn (Builder $query): Builder => $query
                ->where('workspace_id', $workspaceId)
                ->where($subjectColumn, $subjectId))
            ->with(['goodsReceipt', 'stockLot'])
            ->get();

        foreach ($receiptLines as $receiptLine) {
            $candidates[] = [
                'price' => $receiptLine->stockLot->effectiveCostingUnitCost(),
                'currency' => $receiptLine->stockLot->costing_currency
                    ?? $receiptLine->currency,
                'source' => MaterialPriceSource::Receipt,
                'source_id' => $receiptLine->stock_lot_id,
                'recorded_at' => $receiptLine->goodsReceipt->created_at
                    ?? $receiptLine->goodsReceipt->received_at->startOfDay(),
                'created_by_user_id' => null,
                'priority' => 3,
                'stable_id' => 'receipt:'.$receiptLine->id,
            ];
        }

        $orderLines = PurchaseOrderLine::query()
            ->where($subjectColumn, $subjectId)
            ->whereNotNull('pack_price')
            ->whereHas('purchaseOrder', fn (Builder $query): Builder => $query
                ->where('workspace_id', $workspaceId)
                ->whereNotNull('issued_at')
                ->whereIn('status', $this->priceBearingOrderStatuses()))
            ->with('purchaseOrder')
            ->get();

        foreach ($orderLines as $orderLine) {
            $candidates[] = [
                'price' => bcdiv($orderLine->pack_price, $orderLine->canonical_quantity_per_pack, 12),
                'currency' => $orderLine->currency,
                'source' => MaterialPriceSource::ProcurementDocument,
                'source_id' => $orderLine->purchase_order_id,
                'recorded_at' => $orderLine->purchaseOrder->issued_at,
                'created_by_user_id' => null,
                'priority' => 2,
                'stable_id' => 'order:'.$orderLine->id,
            ];
        }

        $listings = SupplierListing::query()
            ->where('workspace_id', $workspaceId)
            ->where($subjectColumn, $subjectId)
            ->where('is_active', true)
            ->whereNotNull('total_price')
            ->whereNotNull('price_recorded_at')
            ->get();

        foreach ($listings as $listing) {
            $candidates[] = [
                'price' => bcdiv($listing->total_price, $listing->canonical_quantity_per_purchase_format, 12),
                'currency' => $listing->currency,
                'source' => MaterialPriceSource::SupplierListing,
                'source_id' => $listing->id,
                'recorded_at' => $listing->price_recorded_at,
                'created_by_user_id' => null,
                'priority' => 1,
                'stable_id' => 'listing:'.$listing->id,
            ];
        }

        usort($candidates, fn (array $left, array $right): int => ($right['recorded_at']->getTimestamp() <=> $left['recorded_at']->getTimestamp())
            ?: ($right['priority'] <=> $left['priority'])
            ?: strcmp($right['stable_id'], $left['stable_id']));

        return $candidates[0] ?? null;
    }

    /** @return array<int, PurchaseOrderStatus> */
    private function priceBearingOrderStatuses(): array
    {
        return [
            PurchaseOrderStatus::Ordered,
            PurchaseOrderStatus::PartiallyReceived,
            PurchaseOrderStatus::Received,
        ];
    }
}
