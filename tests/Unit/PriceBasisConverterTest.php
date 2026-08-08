<?php

use App\Enums\MassUnit;
use App\Services\PriceBasisConverter;

it('converts canonical prices per kilogram to and from prices per pound', function (): void {
    $converter = app(PriceBasisConverter::class);

    $pricePerPound = $converter->fromPerKilogram('10', MassUnit::Pound);
    $pricePerKilogram = $converter->toPerKilogram($pricePerPound, MassUnit::Pound);

    expect($pricePerPound)->toBe('4.535923700')
        ->and($pricePerKilogram)->toBe('10.000000000');
});

it('leaves prices per kilogram unchanged', function (): void {
    $converter = app(PriceBasisConverter::class);

    expect($converter->fromPerKilogram('12.3456', MassUnit::Kilogram))->toBe('12.345600000')
        ->and($converter->toPerKilogram('12.3456', MassUnit::Kilogram))->toBe('12.345600000');
});
