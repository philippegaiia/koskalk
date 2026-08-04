<?php

namespace Database\Factories;

use App\ListingPriceBasis;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\StockLot;
use App\Models\SupplierListing;
use App\StockLotOrigin;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoodsReceiptLine>
 */
class GoodsReceiptLineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'purchase_order_line_id' => fn (): Factory => $this->coherentPurchaseOrderLineFactory(),
            'supplier_listing_id' => function (array $attributes): int|Factory {
                if ($attributes['purchase_order_line_id'] === null) {
                    return SupplierListing::factory();
                }

                return PurchaseOrderLine::query()
                    ->findOrFail($attributes['purchase_order_line_id'])
                    ->supplier_listing_id;
            },
            'goods_receipt_id' => function (array $attributes): Factory {
                if ($attributes['purchase_order_line_id'] !== null) {
                    $orderLine = PurchaseOrderLine::query()->findOrFail($attributes['purchase_order_line_id']);

                    return GoodsReceipt::factory()->for($orderLine->purchaseOrder, 'purchaseOrder');
                }

                $listing = SupplierListing::query()->findOrFail($attributes['supplier_listing_id']);

                return GoodsReceipt::factory()
                    ->for($listing->workspace)
                    ->for($listing->supplier)
                    ->direct();
            },
            'stock_lot_id' => function (array $attributes): Factory {
                $listing = SupplierListing::query()->findOrFail($attributes['supplier_listing_id']);

                return StockLot::factory()->state([
                    'workspace_id' => $listing->workspace_id,
                    'supplier_listing_id' => $listing->id,
                    'ingredient_id' => $listing->ingredient_id,
                    'packaging_item_id' => $listing->packaging_item_id,
                    'organic_status' => $listing->organic_status,
                    'origin' => StockLotOrigin::PurchaseReceipt,
                    'unit_kind' => $listing->unit_kind,
                    'historical_unit_cost' => '0.010000000',
                    'currency' => $listing->currency,
                ]);
            },
            'packs_received' => 1,
            'actual_quantity' => '5000',
            'original_quantity' => '5',
            'original_unit' => 'kg',
            'historical_total_cost' => '50',
            'costing_total_cost' => '50',
            'receipt_price_basis' => ListingPriceBasis::TotalPurchaseFormat,
            'receipt_price_amount' => '50',
            'receipt_price_unit' => null,
            'purchase_format_price' => '50',
            'currency' => 'EUR',
            'costing_currency' => 'EUR',
            'exchange_rate' => '1',
            'exchange_rate_date' => now()->toDateString(),
            'exchange_rate_provider' => 'identity',
            'exchange_rate_is_manual' => false,
            'supplier_batch_number' => null,
            'expires_at' => null,
            'notes' => null,
        ];
    }

    public function direct(): static
    {
        return $this->state(fn (): array => [
            'purchase_order_line_id' => null,
        ]);
    }

    private function coherentPurchaseOrderLineFactory(): Factory
    {
        $listing = SupplierListing::factory()->create();
        $order = PurchaseOrder::factory()
            ->for($listing->workspace)
            ->for($listing->supplier)
            ->create();

        return PurchaseOrderLine::factory()
            ->for($order)
            ->for($listing, 'supplierListing')
            ->state([
                'ingredient_id' => $listing->ingredient_id,
                'packaging_item_id' => $listing->packaging_item_id,
                'unit_kind' => $listing->unit_kind,
                'organic_status' => $listing->organic_status,
            ]);
    }
}
