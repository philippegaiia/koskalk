<?php

namespace App\Actions\Purchasing;

use App\Enums\ProcurementStage;
use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IssueQuotationRequest
{
    public function __construct(private readonly ProductionBenchAccess $access) {}

    public function handle(User $actor, PurchaseOrder $order): PurchaseOrder
    {
        $this->access->assertWritable($actor, $order->workspace);

        return DB::transaction(function () use ($order): PurchaseOrder {
            $lockedOrder = PurchaseOrder::query()
                ->with(['supplier', 'lines.ingredient', 'lines.packagingItem'])
                ->lockForUpdate()
                ->findOrFail($order->id);

            if (
                $lockedOrder->stage !== ProcurementStage::Quotation
                || $lockedOrder->status !== PurchaseOrderStatus::Draft
                || $lockedOrder->quotation_requested_at !== null
            ) {
                throw ValidationException::withMessages([
                    'quotation' => 'Only an unissued quotation draft can be issued.',
                ]);
            }

            $sequence = PurchaseOrder::query()
                ->where('workspace_id', $lockedOrder->workspace_id)
                ->whereNotNull('quotation_reference')
                ->count() + 1;
            $reference = 'RFQ-'.now()->format('ym').'-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
            $supplierSnapshot = $this->supplierSnapshot($lockedOrder);

            $lockedOrder->update([
                'quotation_reference' => $reference,
                'quotation_requested_at' => now(),
                'supplier_snapshot' => $supplierSnapshot,
                'quotation_snapshot' => [
                    'reference' => $reference,
                    'requested_at' => now()->toIso8601String(),
                    'currency' => $lockedOrder->currency,
                    'supplier' => $supplierSnapshot,
                    'lines' => $lockedOrder->lines
                        ->map(fn (PurchaseOrderLine $line): array => $this->lineSnapshot($line, includePrice: false))
                        ->all(),
                    'notes' => $lockedOrder->notes,
                ],
            ]);

            return $lockedOrder->refresh();
        }, attempts: 5);
    }

    /** @return array<string, mixed> */
    private function supplierSnapshot(PurchaseOrder $order): array
    {
        $supplier = $order->supplier;

        return [
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
    }

    /** @return array<string, mixed> */
    private function lineSnapshot(PurchaseOrderLine $line, bool $includePrice): array
    {
        return [
            'line_id' => $line->id,
            'supplier_listing_id' => $line->supplier_listing_id,
            'catalogue_type' => $line->ingredient_id === null ? 'packaging' : 'ingredient',
            'catalogue_id' => $line->ingredient_id ?? $line->packaging_item_id,
            'catalogue_name' => $line->ingredient?->display_name ?? $line->packagingItem?->name,
            'supplier_sku' => $line->supplier_sku,
            'supplier_item_name' => $line->supplier_item_name,
            'purchase_format' => $line->listing_name,
            'unit_kind' => $line->unit_kind->value,
            'ordered_purchase_formats' => $line->ordered_packs,
            'canonical_quantity_per_purchase_format' => $line->canonical_quantity_per_pack,
            'expected_quantity' => $line->expected_quantity,
            'price' => $includePrice ? $line->pack_price : null,
            'currency' => $line->currency,
            'organic_status' => $line->organic_status?->value,
        ];
    }
}
