<?php

namespace App\Actions\Purchasing;

use App\GoodsReceiptSource;
use App\GoodsReceiptStatus;
use App\ListingPriceBasis;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\PurchaseOrderStatus;
use App\Services\MassConverter;
use App\Services\ProductionBenchAccess;
use App\StockUnitKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReceivePurchaseOrder
{
    public function __construct(
        private readonly ProductionBenchAccess $access,
        private readonly MassConverter $massConverter,
        private readonly PostGoodsReceiptLine $postLine,
        private readonly GoodsReceiptInputValidator $inputValidator,
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
        $header = $this->inputValidator->header(
            $idempotencyKey,
            $receivedAt,
            $deliveryReference,
            $notes,
            requiresReceiptDate: false,
        );
        $idempotencyKey = $header['idempotency_key'];
        $receivedAt = $header['received_at'];
        $deliveryReference = $header['delivery_reference'];
        $notes = $header['notes'];
        $workspace = $order->workspace;
        $this->access->assertWritable($actor, $workspace);

        return DB::transaction(function () use (
            $actor,
            $order,
            $workspace,
            $idempotencyKey,
            $deliveryReference,
            $lines,
            $receivedAt,
            $notes,
        ): GoodsReceipt {
            $lockedWorkspace = Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($workspace->id);
            $this->access->assertWritable($actor, $lockedWorkspace);
            $lockedOrder = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);

            if ($lockedOrder->workspace_id !== $lockedWorkspace->id) {
                throw ValidationException::withMessages(['order' => 'The order belongs to another workspace.']);
            }

            $existing = GoodsReceipt::query()
                ->where('workspace_id', $lockedWorkspace->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing instanceof GoodsReceipt) {
                if (
                    $existing->source !== GoodsReceiptSource::PurchaseOrder
                    || $existing->purchase_order_id !== $lockedOrder->id
                ) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'This submission key is already used by another receipt context.',
                    ]);
                }

                return $existing->loadMissing('lines.stockLot');
            }

            if (! in_array($lockedOrder->status, [PurchaseOrderStatus::Ordered, PurchaseOrderStatus::PartiallyReceived], true)) {
                throw ValidationException::withMessages(['order' => 'Only a placed order with outstanding packs can be received.']);
            }

            if ($lines === []) {
                throw ValidationException::withMessages(['lines' => 'Enter at least one received line.']);
            }

            $normalizedLines = [];
            $lineIds = [];

            foreach ($lines as $index => $input) {
                $this->inputValidator->line($input, $index);
                $line = PurchaseOrderLine::query()->lockForUpdate()->findOrFail($input['order_line']->id);

                if (
                    $line->purchase_order_id !== $lockedOrder->id
                    || ! isset($input['packs_received'])
                    || ! is_int($input['packs_received'])
                    || $input['packs_received'] < 1
                ) {
                    throw ValidationException::withMessages(['lines' => 'The received line does not belong to this order.']);
                }

                if (in_array($line->id, $lineIds, true)) {
                    throw ValidationException::withMessages([
                        "lines.$index.order_line" => 'Each order line may only appear once per receipt.',
                    ]);
                }

                $lineIds[] = $line->id;

                if ($line->supplier_listing_id === null) {
                    throw ValidationException::withMessages([
                        'lines' => 'A supplier listing is required before this line can be received.',
                    ]);
                }

                $listing = SupplierListing::query()->lockForUpdate()->findOrFail($line->supplier_listing_id);

                if (
                    $listing->workspace_id !== $lockedWorkspace->id
                    || $listing->supplier_id !== $lockedOrder->supplier_id
                    || $listing->ingredient_id !== $line->ingredient_id
                    || $listing->packaging_item_id !== $line->packaging_item_id
                    || $listing->unit_kind !== $line->unit_kind
                ) {
                    throw ValidationException::withMessages([
                        'lines' => 'The supplier listing no longer matches this ordered line.',
                    ]);
                }

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

                $hasCompletePriceBasis = $line->price_basis !== null && $line->price_amount !== null;
                $normalizedLines[] = [
                    'input' => $input,
                    'line' => $line,
                    'listing' => $listing,
                    'packs_received' => $packsReceived,
                    'actual_quantity' => $actualQuantity,
                    'receipt_price_basis' => $hasCompletePriceBasis
                        ? $line->price_basis
                        : ListingPriceBasis::TotalPurchaseFormat,
                    'receipt_price_amount' => $hasCompletePriceBasis
                        ? $line->price_amount
                        : $line->pack_price,
                    'receipt_price_unit' => $hasCompletePriceBasis ? $line->price_unit : null,
                ];
            }

            $receipt = GoodsReceipt::query()->create([
                'workspace_id' => $lockedWorkspace->id,
                'supplier_id' => $lockedOrder->supplier_id,
                'purchase_order_id' => $lockedOrder->id,
                'source' => GoodsReceiptSource::PurchaseOrder,
                'delivery_reference' => $deliveryReference,
                'received_at' => $receivedAt,
                'status' => GoodsReceiptStatus::Posted,
                'notes' => $notes,
                'received_by_user_id' => $actor->id,
                'idempotency_key' => $idempotencyKey,
            ]);

            foreach ($normalizedLines as $normalizedLine) {
                $input = $normalizedLine['input'];
                $line = $normalizedLine['line'];
                $this->postLine->handle(
                    actor: $actor,
                    workspace: $lockedWorkspace,
                    receipt: $receipt,
                    listing: $normalizedLine['listing'],
                    purchaseOrderLine: $line,
                    packsReceived: $normalizedLine['packs_received'],
                    actualQuantity: $normalizedLine['actual_quantity'],
                    originalQuantity: $input['actual_quantity'],
                    originalUnit: $input['actual_unit'],
                    receiptPriceBasis: $normalizedLine['receipt_price_basis'],
                    receiptPriceAmount: $normalizedLine['receipt_price_amount'],
                    receiptPriceUnit: $normalizedLine['receipt_price_unit'],
                    purchaseFormatPrice: $line->pack_price,
                    currency: $line->currency,
                    movementIdempotencyKey: $this->inputValidator->movementKey(
                        $idempotencyKey,
                        'purchase-order-line:'.$line->id,
                    ),
                    supplierBatchNumber: $input['supplier_batch_number'] ?? null,
                    expiresAt: $input['expires_at'] ?? null,
                    notes: $input['notes'] ?? null,
                );
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
