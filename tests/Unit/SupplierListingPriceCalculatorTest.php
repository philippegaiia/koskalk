<?php

use App\ListingPriceBasis;
use App\Services\SupplierListingPriceCalculator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

it('normalizes a metric mass price quoted per kilogram', function (): void {
    $prices = app(SupplierListingPriceCalculator::class)->forMass(
        netQuantity: '200',
        netUnit: 'kg',
        basis: ListingPriceBasis::PerUnit,
        enteredPrice: '4.20',
        priceUnit: 'kg',
    );

    expect($prices)->toBe([
        'canonical_quantity' => '200000.000000000',
        'price_per_canonical_unit' => '0.004200000',
        'price_per_kg' => '4.200000000',
        'total_price' => '840.000000000',
    ]);
});

it('normalizes a total mass purchase-format price', function (): void {
    $prices = app(SupplierListingPriceCalculator::class)->forMass(
        netQuantity: '200',
        netUnit: 'kg',
        basis: ListingPriceBasis::TotalPurchaseFormat,
        enteredPrice: '900',
        priceUnit: null,
    );

    expect($prices['canonical_quantity'])->toBe('200000.000000000')
        ->and($prices['price_per_kg'])->toBe('4.500000000')
        ->and($prices['total_price'])->toBe('900.000000000');
});

it('preserves exact customary mass conversions without using rounded display prices', function (): void {
    $prices = app(SupplierListingPriceCalculator::class)->forMass(
        netQuantity: '2.5',
        netUnit: 'lb',
        basis: ListingPriceBasis::PerUnit,
        enteredPrice: '0.50',
        priceUnit: 'oz',
    );

    expect($prices)->toBe([
        'canonical_quantity' => '1133.980925000',
        'price_per_canonical_unit' => '0.017636981',
        'price_per_kg' => '17.636980975',
        'total_price' => '20.000000000',
    ]);
});

it('normalizes count listing prices per item and per purchase format', function (): void {
    $calculator = app(SupplierListingPriceCalculator::class);

    expect($calculator->forCount('500', ListingPriceBasis::PerUnit, '0.18'))->toBe([
        'canonical_quantity' => '500.000000000',
        'price_per_canonical_unit' => '0.180000000',
        'price_per_item' => '0.180000000',
        'total_price' => '90.000000000',
    ])->and($calculator->forCount('500', ListingPriceBasis::TotalPurchaseFormat, '100'))->toBe([
        'canonical_quantity' => '500.000000000',
        'price_per_canonical_unit' => '0.200000000',
        'price_per_item' => '0.200000000',
        'total_price' => '100.000000000',
    ]);
});

it('rejects invalid listing quantities, prices, and mass price units', function (
    string $quantity,
    ListingPriceBasis $basis,
    string $price,
    ?string $priceUnit,
): void {
    expect(fn (): array => app(SupplierListingPriceCalculator::class)->forMass(
        netQuantity: $quantity,
        netUnit: 'kg',
        basis: $basis,
        enteredPrice: $price,
        priceUnit: $priceUnit,
    ))->toThrow(ValidationException::class);
})->with([
    'zero quantity' => ['0', ListingPriceBasis::TotalPurchaseFormat, '10', null],
    'negative quantity' => ['-1', ListingPriceBasis::TotalPurchaseFormat, '10', null],
    'zero price' => ['1', ListingPriceBasis::TotalPurchaseFormat, '0', null],
    'negative price' => ['1', ListingPriceBasis::TotalPurchaseFormat, '-1', null],
    'missing per-unit mass unit' => ['1', ListingPriceBasis::PerUnit, '10', null],
    'unsupported per-unit mass unit' => ['1', ListingPriceBasis::PerUnit, '10', 'count'],
]);

it('rejects fractional, zero, and negative counts', function (string $quantity): void {
    expect(fn (): array => app(SupplierListingPriceCalculator::class)->forCount(
        netQuantity: $quantity,
        basis: ListingPriceBasis::PerUnit,
        enteredPrice: '1',
    ))->toThrow(ValidationException::class);
})->with(['500.5', '0', '-3']);
