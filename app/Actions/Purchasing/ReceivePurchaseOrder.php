<?php

namespace App\Actions\Purchasing;

use App\GoodsReceiptSource;
use App\GoodsReceiptStatus;
use App\ListingPriceBasis;
use App\MaterialPriceSource;
use App\Models\GoodsReceipt;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\StockLot;
use App\Models\User;
use App\PurchaseOrderStatus;
use App\Services\CurrentMaterialPriceService;
use App\Services\InternalLotCodeGenerator;
use App\Services\MassConverter;
use App\Services\ProductionBenchAccess;
use App\StockLotOrigin;
use App\StockLotStatus;
use App\StockMovementType;
use App\StockUnitKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReceivePurchaseOrder
{
    public function __construct(
        private readonly ProductionBenchAccess $access,
        private readonly MassConverter $massConverter,
        private readonly InternalLotCodeGenerator $lotCodeGenerator,
        private readonly CurrentMaterialPriceService $currentMaterialPriceService,
    ) {}

    /**
     * @param  array<int, array{
     *   order_line: PurchaseOrderLine,
     *   packs_received: int,
     *   actual_quantity: string,
     *   actual_unit: string,
     *   supplier_batch_number?: ?string,
     *   expires_at?: ?string,
     *   notes?: ?string,
     * }>  $lines
     */
    public function handle(
        User $actor,
        PurchaseOrder $order,
        string $idempotencyKey,
        ?string $deliveryReference,
        array $lines,
        ?string $receivedAt = null,
        ?string $notes = null,
    ): GoodsReceipt {
        $this->access->assertWritable($actor, $order->workspace);

        return DB::transaction(function () use (
            $actor,
            $order,
            $idempotencyKey,
            $deliveryReference,
            $lines,
            $receivedAt,
            $notes,
        ): GoodsReceipt {
            $existing = GoodsReceipt::query()
                ->where('workspace_id', $order->workspace_id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing instanceof GoodsReceipt) {
                return $existing;
            }

            $lockedOrder = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);

            if (! in_array($lockedOrder->status, [PurchaseOrderStatus::Ordered, PurchaseOrderStatus::PartiallyReceived], true)) {
                throw ValidationException::withMessages(['order' => 'Only a placed order with outstanding packs can be received.']);
            }

            if ($lines === []) {
                throw ValidationException::withMessages(['lines' => 'Enter at least one received line.']);
            }

            $lockedLines = [];

            foreach ($lines as $input) {
                $line = PurchaseOrderLine::query()->lockForUpdate()->findOrFail($input['order_line']->id);

                if ($line->purchase_order_id !== $lockedOrder->id || $input['packs_received'] < 1) {
                    throw ValidationException::withMessages(['lines' => 'The received line does not belong to this order.']);
                }

                if ($line->supplier_listing_id === null) {
                    throw ValidationException::withMessages([
                        'lines' => 'A supplier listing is required before this line can be received.',
                    ]);
                }

                $lockedLines[] = [$input, $line];
            }

            $receipt = GoodsReceipt::query()->create([
                'workspace_id' => $lockedOrder->workspace_id,
                'supplier_id' => $lockedOrder->supplier_id,
                'purchase_order_id' => $lockedOrder->id,
                'source' => GoodsReceiptSource::PurchaseOrder,
                'delivery_reference' => $deliveryReference,
                'received_at' => $receivedAt ?? now()->toDateString(),
                'status' => GoodsReceiptStatus::Posted,
                'notes' => $notes,
                'received_by_user_id' => $actor->id,
                'idempotency_key' => $idempotencyKey,
            ]);

            foreach ($lockedLines as [$input, $line]) {
                $alreadyReceived = (int) $line->receiptLines()
                    ->whereHas('goodsReceipt', fn ($query) => $query->where('status', GoodsReceiptStatus::Posted))
                    ->sum('packs_received');
                $packsReceived = $input['packs_received'];

                if ($alreadyReceived + $packsReceived > $line->ordered_packs) {
                    throw ValidationException::withMessages(['lines' => 'Received packs cannot exceed the ordered quantity.']);
                }

                $actualQuantity = $line->unit_kind === StockUnitKind::Mass
                    ? $this->massConverter->toGrams($input['actual_quantity'], $input['actual_unit'])
                    : $this->countQuantity($input['actual_quantity'], $input['actual_unit']);

                if (bccomp($actualQuantity, '0', 9) <= 0) {
                    throw ValidationException::withMessages(['actual_quantity' => 'Actual received quantity must be positive.']);
                }

                $historicalTotalCost = bcmul($line->pack_price, (string) $packsReceived, 9);
                $hasCompletePriceBasis = $line->price_basis !== null && $line->price_amount !== null;
                $receiptPriceBasis = $hasCompletePriceBasis
                    ? $line->price_basis
                    : ListingPriceBasis::TotalPurchaseFormat;
                $receiptPriceAmount = $hasCompletePriceBasis
                    ? $line->price_amount
                    : $line->pack_price;
                $receiptPriceUnit = $hasCompletePriceBasis ? $line->price_unit : null;
                $status = StockLotStatus::Released;
                $lot = StockLot::query()->create([
                    'workspace_id' => $lockedOrder->workspace_id,
                    'supplier_listing_id' => $line->supplier_listing_id,
                    'ingredient_id' => $line->ingredient_id,
                    'packaging_item_id' => $line->packaging_item_id,
                    'organic_status' => $line->organic_status,
                    'internal_lot_code' => $this->lotCodeGenerator->next($lockedOrder->workspace),
                    'supplier_batch_number' => $input['supplier_batch_number'] ?? null,
                    'origin' => StockLotOrigin::PurchaseReceipt,
                    'unit_kind' => $line->unit_kind,
                    'status' => $status,
                    'stocked_at' => $receivedAt ?? now()->toDateString(),
                    'expires_at' => $input['expires_at'] ?? null,
                    'available_from' => $status === StockLotStatus::Released ? ($receivedAt ?? now()->toDateString()) : null,
                    'released_at' => $status === StockLotStatus::Released ? now() : null,
                    'released_by_user_id' => $status === StockLotStatus::Released ? $actor->id : null,
                    'provenance_complete' => filled($input['supplier_batch_number'] ?? null),
                    'historical_unit_cost' => bcdiv($historicalTotalCost, $actualQuantity, 9),
                    'currency' => $line->currency,
                ]);

                $receiptLine = $receipt->lines()->create([
                    'purchase_order_line_id' => $line->id,
                    'supplier_listing_id' => $line->supplier_listing_id,
                    'stock_lot_id' => $lot->id,
                    'packs_received' => $packsReceived,
                    'actual_quantity' => $actualQuantity,
                    'original_quantity' => $input['actual_quantity'],
                    'original_unit' => $input['actual_unit'],
                    'historical_total_cost' => $historicalTotalCost,
                    'receipt_price_basis' => $receiptPriceBasis,
                    'receipt_price_amount' => $receiptPriceAmount,
                    'receipt_price_unit' => $receiptPriceUnit,
                    'purchase_format_price' => $line->pack_price,
                    'currency' => $line->currency,
                    'supplier_batch_number' => $input['supplier_batch_number'] ?? null,
                    'expires_at' => $input['expires_at'] ?? null,
                    'notes' => $input['notes'] ?? null,
                ]);

                $lot->movements()->create([
                    'workspace_id' => $lockedOrder->workspace_id,
                    'type' => StockMovementType::PurchaseReceipt,
                    'quantity_delta' => $actualQuantity,
                    'original_quantity' => $input['actual_quantity'],
                    'original_unit' => $input['actual_unit'],
                    'occurred_at' => now(),
                    'actor_user_id' => $actor->id,
                    'source_type' => $receiptLine->getMorphClass(),
                    'source_id' => $receiptLine->id,
                    'idempotency_key' => $idempotencyKey.':'.$line->id,
                ]);

                if ($line->ingredient_id !== null) {
                    $ingredient = Ingredient::withoutGlobalScopes()->findOrFail($line->ingredient_id);
                    $this->currentMaterialPriceService->rememberIngredient(
                        workspace: $lockedOrder->workspace,
                        ingredient: $ingredient,
                        pricePerMassUnit: $lot->historical_unit_cost,
                        massUnit: 'g',
                        currency: $line->currency,
                        source: MaterialPriceSource::Receipt,
                        sourceId: $lot->id,
                        actor: $actor,
                    );
                } else {
                    $packagingItem = PackagingItem::query()->findOrFail($line->packaging_item_id);
                    $this->currentMaterialPriceService->rememberPackaging(
                        workspace: $lockedOrder->workspace,
                        packagingItem: $packagingItem,
                        pricePerItem: $lot->historical_unit_cost,
                        currency: $line->currency,
                        source: MaterialPriceSource::Receipt,
                        sourceId: $lot->id,
                        actor: $actor,
                    );
                }
            }

            $outstanding = $lockedOrder->lines()
                ->get()
                ->contains(function (PurchaseOrderLine $line): bool {
                    $received = (int) $line->receiptLines()
                        ->whereHas('goodsReceipt', fn ($query) => $query->where('status', GoodsReceiptStatus::Posted))
                        ->sum('packs_received');

                    return $received < $line->ordered_packs;
                });

            $lockedOrder->update([
                'status' => $outstanding ? PurchaseOrderStatus::PartiallyReceived : PurchaseOrderStatus::Received,
            ]);

            return $receipt->load('lines.stockLot');
        }, attempts: 5);
    }

    private function countQuantity(string $quantity, string $unit): string
    {
        if ($unit !== 'count' || preg_match('/^[1-9]\d*$/', $quantity) !== 1) {
            throw ValidationException::withMessages(['actual_quantity' => 'Packaging receipts require a positive whole count.']);
        }

        return bcadd($quantity, '0', 9);
    }
}
