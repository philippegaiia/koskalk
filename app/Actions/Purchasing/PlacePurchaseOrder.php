<?php

namespace App\Actions\Purchasing;

use App\Enums\MaterialPriceSource;
use App\Enums\ProcurementStage;
use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use App\Services\CurrentMaterialPriceService;
use App\Services\ProcurementLineSnapshotBuilder;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlacePurchaseOrder
{
    public function __construct(
        private readonly ProductionBenchAccess $access,
        private readonly CurrentMaterialPriceService $currentMaterialPriceService,
        private readonly ProcurementLineSnapshotBuilder $lineSnapshotBuilder,
    ) {}

    /** @param array<string, string|null> $deliveryAddress */
    public function handle(
        User $actor,
        PurchaseOrder $order,
        array $deliveryAddress = [],
        string $shippingAmount = '0',
        string $discountAmount = '0',
        string $taxAmount = '0',
    ): PurchaseOrder {
        $this->access->assertWritable($actor, $order->workspace);

        foreach ([$shippingAmount, $discountAmount, $taxAmount] as $amount) {
            if (preg_match('/^\d+(?:\.\d+)?$/', trim($amount)) !== 1) {
                throw ValidationException::withMessages(['totals' => 'Order adjustments must be non-negative decimal amounts.']);
            }
        }

        return DB::transaction(function () use ($actor, $order, $deliveryAddress, $shippingAmount, $discountAmount, $taxAmount): PurchaseOrder {
            $lockedOrder = PurchaseOrder::query()
                ->with(['supplier', 'lines.ingredient', 'lines.packagingItem'])
                ->lockForUpdate()
                ->findOrFail($order->id);

            if (
                $lockedOrder->stage !== ProcurementStage::PurchaseOrder
                || $lockedOrder->status !== PurchaseOrderStatus::Draft
                || $lockedOrder->lines->contains(fn (PurchaseOrderLine $line): bool => $line->pack_price === null)
            ) {
                throw ValidationException::withMessages(['order' => 'Only a draft order can be placed.']);
            }

            $supplier = $lockedOrder->supplier;
            $supplierSnapshot = [
                'code' => $supplier->code,
                'name' => $supplier->name,
                'contact_name' => $supplier->contact_name,
                'email' => $supplier->email,
                'phone' => $supplier->phone,
                'address_line_1' => $supplier->address_line_1,
                'address_line_2' => $supplier->address_line_2,
                'city' => $supplier->city,
                'region' => $supplier->region,
                'postal_code' => $supplier->postal_code,
                'country_code' => $supplier->country_code,
            ];
            $subtotal = $lockedOrder->lines->reduce(
                fn (string $total, PurchaseOrderLine $line): string => bcadd($total, $line->expected_cost, 9),
                '0',
            );
            $total = bcadd(
                bcsub(bcadd($subtotal, $shippingAmount, 9), $discountAmount, 9),
                $taxAmount,
                9,
            );
            $issuedAt = now();
            $lineSnapshots = $lockedOrder->lines
                ->map(fn (PurchaseOrderLine $line): array => $this->lineSnapshotBuilder->build($line, includePrice: true))
                ->all();

            $lockedOrder->update([
                'status' => PurchaseOrderStatus::Ordered,
                'ordered_at' => $issuedAt->toDateString(),
                'issued_at' => $issuedAt,
                'supplier_snapshot' => $supplierSnapshot,
                'delivery_address_snapshot' => $deliveryAddress,
                'shipping_amount' => $shippingAmount,
                'discount_amount' => $discountAmount,
                'tax_amount' => $taxAmount,
                'purchase_order_snapshot' => [
                    'reference' => $lockedOrder->reference,
                    'issued_at' => $issuedAt->toIso8601String(),
                    'expected_at' => $lockedOrder->expected_at?->toDateString(),
                    'currency' => $lockedOrder->currency,
                    'supplier' => $supplierSnapshot,
                    'delivery_address' => $deliveryAddress,
                    'lines' => $lineSnapshots,
                    'subtotal' => bcadd($subtotal, '0', 9),
                    'shipping' => bcadd($shippingAmount, '0', 9),
                    'discount' => bcadd($discountAmount, '0', 9),
                    'tax' => bcadd($taxAmount, '0', 9),
                    'total' => $total,
                    'notes' => $lockedOrder->notes,
                ],
            ]);

            foreach ($lockedOrder->lines as $line) {
                $pricePerCanonicalUnit = bcdiv($line->pack_price, $line->canonical_quantity_per_pack, 12);

                if ($line->ingredient !== null) {
                    $this->currentMaterialPriceService->rememberIngredient(
                        workspace: $lockedOrder->workspace,
                        ingredient: $line->ingredient,
                        pricePerMassUnit: $pricePerCanonicalUnit,
                        massUnit: 'g',
                        currency: $line->currency,
                        source: MaterialPriceSource::ProcurementDocument,
                        sourceId: $lockedOrder->id,
                        actor: $actor,
                        recordedAt: $issuedAt,
                    );
                } else {
                    $this->currentMaterialPriceService->rememberPackaging(
                        workspace: $lockedOrder->workspace,
                        packagingItem: $line->packagingItem,
                        pricePerItem: $pricePerCanonicalUnit,
                        currency: $line->currency,
                        source: MaterialPriceSource::ProcurementDocument,
                        sourceId: $lockedOrder->id,
                        actor: $actor,
                        recordedAt: $issuedAt,
                    );
                }
            }

            return $lockedOrder->refresh();
        }, attempts: 5);
    }
}
