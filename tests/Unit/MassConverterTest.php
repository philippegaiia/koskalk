<?php

use App\MassDisplaySystem;
use App\MassUnit;
use App\Services\MassConverter;

it('defines the exact gram factor for each supported mass unit', function (MassUnit $unit, string $factor): void {
    expect($unit->gramsPerUnit())->toBe($factor);
})->with([
    'gram' => [MassUnit::Gram, '1'],
    'kilogram' => [MassUnit::Kilogram, '1000'],
    'ounce' => [MassUnit::Ounce, '28.349523125'],
    'pound' => [MassUnit::Pound, '453.59237'],
]);

it('converts supported mass quantities with nine decimal places', function (
    string $quantity,
    MassUnit $from,
    MassUnit $to,
    string $expected,
): void {
    expect(app(MassConverter::class)->convert($quantity, $from, $to))->toBe($expected);
})->with([
    'kilograms to pounds' => ['1', MassUnit::Kilogram, MassUnit::Pound, '2.204622622'],
    'pounds to ounces' => ['1', MassUnit::Pound, MassUnit::Ounce, '16.000000000'],
    'ounces to grams' => ['1', MassUnit::Ounce, MassUnit::Gram, '28.349523125'],
]);

it('preserves a physical mass across a unit round trip', function (): void {
    $converter = app(MassConverter::class);
    $pounds = $converter->convert('1', MassUnit::Kilogram, MassUnit::Pound);

    expect($converter->convert($pounds, MassUnit::Pound, MassUnit::Kilogram))
        ->toBe('1.000000000');
});

it('selects sensible metric display units', function (string $grams, MassUnit $expected): void {
    expect(MassDisplaySystem::Metric->preferredUnit($grams))->toBe($expected);
})->with([
    'below one kilogram' => ['999', MassUnit::Gram],
    'one kilogram' => ['1000', MassUnit::Kilogram],
]);

it('selects sensible US customary display units', function (string $grams, MassUnit $expected): void {
    expect(MassDisplaySystem::UsCustomary->preferredUnit($grams))->toBe($expected);
})->with([
    'below one pound' => ['452', MassUnit::Ounce],
    'one pound' => ['453.59237', MassUnit::Pound],
]);

it('rejects unsupported units', function (): void {
    MassUnit::fromInput('stone');
})->throws(InvalidArgumentException::class);

it('rejects negative quantities', function (): void {
    app(MassConverter::class)->toGrams('-1', MassUnit::Gram);
})->throws(InvalidArgumentException::class);

it('converts signed balances while preserving their sign', function (): void {
    expect(app(MassConverter::class)->fromGramsSigned('-1000', MassUnit::Kilogram))
        ->toBe('-1.000000000');
});

it('accepts comma decimal input from localized production forms', function (): void {
    expect(app(MassConverter::class)->toGrams('1,5', MassUnit::Kilogram))
        ->toBe('1500.000000000');
});
