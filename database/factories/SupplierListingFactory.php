<?php

namespace Database\Factories;

use App\ListingPriceBasis;
use App\Models\Ingredient;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\Workspace;
use App\StockUnitKind;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierListing>
 */
class SupplierListingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'supplier_id' => Supplier::factory(),
            'ingredient_id' => Ingredient::factory(),
            'user_packaging_item_id' => null,
            'supplier_sku' => fake()->bothify('SKU-####'),
            'supplier_name' => null,
            'purchase_format' => '5 kg pail',
            'container' => 'pail',
            'unit_kind' => StockUnitKind::Mass,
            'canonical_quantity_per_purchase_format' => '5000',
            'net_quantity' => '5',
            'net_unit' => 'kg',
            'price_basis' => ListingPriceBasis::TotalPurchaseFormat,
            'price_amount' => '50',
            'price_unit' => null,
            'price_recorded_at' => now(),
            'total_price' => '50',
            'currency' => 'EUR',
            'minimum_packs' => 1,
            'notes' => null,
            'is_active' => true,
        ];
    }
}
