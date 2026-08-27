<?php

namespace App\Services;

use App\Models\PurchaseOrderLine;

class ProcurementLineSnapshotBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(PurchaseOrderLine $line, bool $includePrice): array
    {
        $snapshot = [
            'line_id' => $line->id,
            'supplier_listing_id' => $line->supplier_listing_id,
            'catalogue_type' => $line->ingredient_id === null ? 'packaging' : 'ingredient',
            'catalogue_id' => $line->ingredient_id ?? $line->packaging_item_id,
            'catalogue_name' => $line->ingredient?->display_name ?? $line->packagingItem?->name,
            'material_code' => $line->material_code_snapshot,
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

        if ($includePrice) {
            $snapshot = [
                ...$snapshot,
                'price_basis' => $line->price_basis?->value,
                'price_amount' => $line->price_amount,
                'price_unit' => $line->price_unit,
                'expected_cost' => $line->expected_cost,
            ];
        }

        return $snapshot;
    }
}
