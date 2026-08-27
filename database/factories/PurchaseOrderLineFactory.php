<?php

namespace Database\Factories;

use App\Enums\ListingPriceBasis;
use App\Enums\StockUnitKind;
use App\Models\Ingredient;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SupplierListing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrderLine>
 */
class PurchaseOrderLineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'supplier_listing_id' => SupplierListing::factory(),
            'ingredient_id' => Ingredient::factory(),
            'packaging_item_id' => null,
            'supplier_sku' => fake()->bothify('SKU-####'),
            'supplier_item_name' => null,
            'listing_name' => '5 kg pail',
            'material_code_snapshot' => null,
            'unit_kind' => StockUnitKind::Mass,
            'ordered_packs' => 1,
            'canonical_quantity_per_pack' => '5000',
            'pack_price' => '50',
            'price_basis' => ListingPriceBasis::TotalPurchaseFormat,
            'price_amount' => '50',
            'price_unit' => null,
            'price_recorded_at' => now(),
            'currency' => 'EUR',
            'expected_quantity' => '5000',
            'expected_cost' => '50',
        ];
    }
}
