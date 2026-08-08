<?php

use App\Enums\ListingPriceBasis;
use App\Enums\StockUnitKind;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\Supplier;
use App\Models\SupplierListing;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores structured supplier contact and address data', function (): void {
    $supplier = Supplier::factory()->create([
        'address_line_1' => '12 Rue des Fleurs',
        'address_line_2' => 'Building B',
        'city' => 'Grasse',
        'region' => 'Provence-Alpes-Côte d’Azur',
        'postal_code' => '06130',
        'country_code' => 'FR',
        'website' => 'https://example.test',
    ]);

    expect($supplier->address_line_1)->toBe('12 Rue des Fleurs')
        ->and($supplier->address_line_2)->toBe('Building B')
        ->and($supplier->city)->toBe('Grasse')
        ->and($supplier->region)->toBe('Provence-Alpes-Côte d’Azur')
        ->and($supplier->postal_code)->toBe('06130')
        ->and($supplier->country_code)->toBe('FR')
        ->and($supplier->website)->toBe('https://example.test');
});

it('stores a supplier purchase format with a normalized unit price', function (): void {
    $listing = SupplierListing::factory()->create([
        'purchase_format' => 'Drum',
        'net_quantity' => '200',
        'net_unit' => 'kg',
        'canonical_quantity_per_purchase_format' => '200000',
        'price_basis' => ListingPriceBasis::PerUnit,
        'price_amount' => '4.20',
        'price_unit' => 'kg',
        'total_price' => '840.00',
    ]);

    expect($listing->purchase_format)->toBe('Drum')
        ->and($listing->net_quantity)->toBe('200.000000000')
        ->and($listing->net_unit)->toBe('kg')
        ->and($listing->canonical_quantity_per_purchase_format)->toBe('200000.000000000')
        ->and($listing->price_basis)->toBe(ListingPriceBasis::PerUnit)
        ->and($listing->price_amount)->toBe('4.200000000')
        ->and($listing->price_unit)->toBe('kg')
        ->and($listing->total_price)->toBe('840.000000000');
});

it('stores a count purchase format with a total purchase price', function (): void {
    $listing = SupplierListing::factory()->create([
        'ingredient_id' => null,
        'packaging_item_id' => PackagingItem::factory(),
        'unit_kind' => StockUnitKind::Count,
        'purchase_format' => 'Carton',
        'net_quantity' => '24',
        'net_unit' => 'units',
        'canonical_quantity_per_purchase_format' => '24',
        'price_basis' => ListingPriceBasis::TotalPurchaseFormat,
        'price_amount' => '36.00',
        'price_unit' => null,
        'total_price' => '36.00',
    ]);

    expect($listing->unit_kind)->toBe(StockUnitKind::Count)
        ->and($listing->net_quantity)->toBe('24.000000000')
        ->and($listing->canonical_quantity_per_purchase_format)->toBe('24.000000000')
        ->and($listing->price_basis)->toBe(ListingPriceBasis::TotalPurchaseFormat)
        ->and($listing->price_amount)->toBe('36.000000000')
        ->and($listing->price_unit)->toBeNull()
        ->and($listing->total_price)->toBe('36.000000000');
});

it('rejects a listing that references both an ingredient and a packaging item', function (): void {
    $supplier = Supplier::factory()->create();
    $ingredient = Ingredient::factory()->create();
    $packagingItem = PackagingItem::factory()->create();

    expect(fn (): SupplierListing => SupplierListing::query()->create([
        'workspace_id' => $supplier->workspace_id,
        'supplier_id' => $supplier->id,
        'ingredient_id' => $ingredient->id,
        'packaging_item_id' => $packagingItem->id,
        'purchase_format' => 'Carton',
        'unit_kind' => StockUnitKind::Mass,
        'canonical_quantity_per_purchase_format' => '1000',
        'total_price' => '12',
        'currency' => 'EUR',
    ]))->toThrow(QueryException::class);
});
