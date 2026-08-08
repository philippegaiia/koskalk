<?php

use App\Enums\ListingPriceBasis;
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

it('calculates mass prices from the quantity normalized to storage precision', function (
    string $unit,
    string $canonicalQuantity,
): void {
    $prices = app(SupplierListingPriceCalculator::class)->forMass(
        netQuantity: '1.0000000006',
        netUnit: $unit,
        basis: ListingPriceBasis::PerUnit,
        enteredPrice: '2',
        priceUnit: $unit,
    );

    expect($prices['canonical_quantity'])->toBe($canonicalQuantity)
        ->and($prices['total_price'])->toBe('2.000000002');
})->with([
    'grams' => ['g', '1.000000001'],
    'kilograms' => ['kg', '1000.000001000'],
    'pounds' => ['lb', '453.592370454'],
    'ounces' => ['oz', '28.349523153'],
]);

it('accepts a mass quantity at the decimal 20 scale 9 storage boundary', function (): void {
    expect(app(SupplierListingPriceCalculator::class)
        ->normalizeMassQuantity('99999999999.9999999994'))
        ->toBe('99999999999.999999999');
});

it('rejects mass quantities that round to zero before conversion', function (string $unit): void {
    expectStorageDecimalValidation(
        fn (): array => app(SupplierListingPriceCalculator::class)->forMass(
            netQuantity: '0.0000000004',
            netUnit: $unit,
            basis: ListingPriceBasis::TotalPurchaseFormat,
            enteredPrice: '1',
            priceUnit: null,
        ),
        'net_quantity',
    );
})->with([
    'kilograms' => ['kg'],
    'pounds' => ['lb'],
    'ounces' => ['oz'],
]);

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

it('accepts values at the decimal 20 scale 9 storage boundary', function (): void {
    $prices = app(SupplierListingPriceCalculator::class)->forCount(
        '99999999999',
        ListingPriceBasis::TotalPurchaseFormat,
        '99999999999.999999999',
    );

    expect($prices['canonical_quantity'])->toBe('99999999999.000000000')
        ->and($prices['price_per_item'])->toBe('1.000000000')
        ->and($prices['total_price'])->toBe('99999999999.999999999');
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

it('rejects mass values that cannot be stored as positive decimal 20 scale 9 values', function (
    string $quantity,
    ListingPriceBasis $basis,
    string $price,
    ?string $priceUnit,
    string $field,
): void {
    expectStorageDecimalValidation(
        fn (): array => app(SupplierListingPriceCalculator::class)->forMass(
            netQuantity: $quantity,
            netUnit: 'g',
            basis: $basis,
            enteredPrice: $price,
            priceUnit: $priceUnit,
        ),
        $field,
    );
})->with([
    'quantity rounds to zero' => [
        '0.0000000001', ListingPriceBasis::TotalPurchaseFormat, '1', null, 'net_quantity',
    ],
    'price rounds to zero' => [
        '1', ListingPriceBasis::TotalPurchaseFormat, '0.0000000001', null, 'price_amount',
    ],
    'quantity exceeds eleven integer digits' => [
        '100000000000', ListingPriceBasis::TotalPurchaseFormat, '1', null, 'net_quantity',
    ],
    'price exceeds eleven integer digits' => [
        '1', ListingPriceBasis::TotalPurchaseFormat, '100000000000', null, 'price_amount',
    ],
    'derived canonical price rounds to zero' => [
        '99999999999', ListingPriceBasis::TotalPurchaseFormat, '0.000000001', null, 'price_per_canonical_unit',
    ],
    'derived kilogram price exceeds storage' => [
        '1', ListingPriceBasis::PerUnit, '99999999999', 'g', 'price_per_kg',
    ],
    'derived total exceeds storage' => [
        '99999999999', ListingPriceBasis::PerUnit, '2', 'g', 'total_price',
    ],
]);

it('rejects count values whose canonical, per-item, or total values cannot be stored', function (
    string $quantity,
    ListingPriceBasis $basis,
    string $price,
    string $field,
): void {
    expectStorageDecimalValidation(
        fn (): array => app(SupplierListingPriceCalculator::class)->forCount($quantity, $basis, $price),
        $field,
    );
})->with([
    'canonical count exceeds storage' => [
        '100000000000', ListingPriceBasis::PerUnit, '1', 'net_quantity',
    ],
    'per-item total price rounds to zero' => [
        '99999999999', ListingPriceBasis::TotalPurchaseFormat, '0.000000001', 'price_per_item',
    ],
    'count-derived total exceeds storage' => [
        '99999999999', ListingPriceBasis::PerUnit, '2', 'total_price',
    ],
]);

function expectStorageDecimalValidation(Closure $operation, string $field): void
{
    try {
        $operation();
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field)
            ->and($exception->errors()[$field])->toBe([
                'The value must fit a positive DECIMAL(20,9) amount.',
            ]);

        return;
    }

    test()->fail("Expected a validation error for {$field}.");
}
