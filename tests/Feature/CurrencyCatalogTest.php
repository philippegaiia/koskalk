<?php

use App\Services\CurrencyCatalog;

it('provides localized names from maintained ICU data', function () {
    $catalog = app(CurrencyCatalog::class);

    expect($catalog->name('USD', 'fr'))->toBe('dollar des États-Unis')
        ->and($catalog->name('EUR', 'nl'))->toBe('Euro')
        ->and($catalog->symbol('EUR', 'de'))->toBe('€')
        ->and($catalog->fractionDigits('JPY'))->toBe(0);
});

it('offers current legal tender while retaining historical display fallbacks', function () {
    $catalog = app(CurrencyCatalog::class);

    expect($catalog->selectableCodes())
        ->toContain('EUR', 'USD', 'GBP')
        ->not->toContain('HRK')
        ->and($catalog->name('HRK', 'en'))->toBe('Croatian Kuna')
        ->and($catalog->name('ZZZ', 'en'))->toBe('ZZZ');
});

it('can include a stored historical currency in localized choices', function () {
    $options = app(CurrencyCatalog::class)->options('de', ['HRK']);

    expect($options)->toHaveKey('HRK')
        ->and($options['HRK'])->toBe('Kroatischer Kuna')
        ->and(array_keys($options))->not->toContain('ZWL');
});
