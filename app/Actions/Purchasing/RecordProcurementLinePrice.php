<?php

namespace App\Actions\Purchasing;

use App\ListingPriceBasis;
use App\MaterialPriceSource;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use App\ProcurementStage;
use App\PurchaseOrderStatus;
use App\Services\CurrentMaterialPriceService;
use App\Services\ProductionBenchAccess;
use App\Services\SupplierListingPriceCalculator;
use App\StockUnitKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordProcurementLinePrice
{
    public function __construct(
        private readonly ProductionBenchAccess $access,
        private readonly SupplierListingPriceCalculator $priceCalculator,
        private readonly CurrentMaterialPriceService $currentMaterialPriceService,
    ) {}

    public function handle(
        User $actor,
        PurchaseOrderLine $line,
        ListingPriceBasis $basis,
        string $amount,
        ?string $unit = null,
    ): PurchaseOrderLine {
        $order = $line->purchaseOrder;
        $this->access->assertWritable($actor, $order->workspace);

        return DB::transaction(function () use ($actor, $line, $basis, $amount, $unit): PurchaseOrderLine {
            $lockedLine = PurchaseOrderLine::query()
                ->with(['purchaseOrder.workspace', 'supplierListing', 'ingredient', 'packagingItem'])
                ->lockForUpdate()
                ->findOrFail($line->id);
            $order = $lockedLine->purchaseOrder;

            $isIssuedQuotation = $order->stage === ProcurementStage::Quotation
                && $order->quotation_requested_at !== null;
            $isDraftPurchaseOrder = $order->stage === ProcurementStage::PurchaseOrder
                && $order->status === PurchaseOrderStatus::Draft;

            if ((! $isIssuedQuotation && ! $isDraftPurchaseOrder) || $order->issued_at !== null) {
                throw ValidationException::withMessages([
                    'price' => 'Prices can only be confirmed before purchase-order issue.',
                ]);
            }

            $prices = $lockedLine->unit_kind === StockUnitKind::Mass
                ? $this->priceCalculator->forMass(
                    $lockedLine->canonical_quantity_per_pack,
                    'g',
                    $basis,
                    $amount,
                    $unit,
                )
                : $this->priceCalculator->forCount(
                    explode('.', $lockedLine->canonical_quantity_per_pack)[0],
                    $basis,
                    $amount,
                );
            $recordedAt = now();

            $lockedLine->update([
                'price_basis' => $basis,
                'price_amount' => $amount,
                'price_unit' => $unit,
                'price_recorded_at' => $recordedAt,
                'pack_price' => $prices['total_price'],
                'expected_cost' => bcmul($prices['total_price'], (string) $lockedLine->ordered_packs, 9),
            ]);

            if (! $order->lines()->whereNull('pack_price')->exists()) {
                $order->update(['price_confirmed_at' => $recordedAt]);
            }

            $listing = $lockedLine->supplierListing;
            $listing->update([
                'price_basis' => $basis,
                'price_amount' => $amount,
                'price_unit' => $unit,
                'price_recorded_at' => $recordedAt,
                'total_price' => $prices['total_price'],
            ]);

            if ($lockedLine->ingredient !== null) {
                $this->currentMaterialPriceService->rememberIngredient(
                    workspace: $order->workspace,
                    ingredient: $lockedLine->ingredient,
                    pricePerMassUnit: $prices['price_per_canonical_unit'],
                    massUnit: 'g',
                    currency: $lockedLine->currency,
                    source: MaterialPriceSource::ProcurementDocument,
                    sourceId: $order->id,
                    actor: $actor,
                    recordedAt: $recordedAt,
                );
            } else {
                $this->currentMaterialPriceService->rememberPackaging(
                    workspace: $order->workspace,
                    packagingItem: $lockedLine->packagingItem,
                    pricePerItem: $prices['price_per_canonical_unit'],
                    currency: $lockedLine->currency,
                    source: MaterialPriceSource::ProcurementDocument,
                    sourceId: $order->id,
                    actor: $actor,
                    recordedAt: $recordedAt,
                );
            }

            return $lockedLine->refresh();
        }, attempts: 5);
    }
}
