<?php

use App\Support\InciName;

it('normalizes INCI names consistently for exact catalogue matching', function (): void {
    expect(InciName::normalize('  Olea   Europaea Fruit Oil '))->toBe('OLEA EUROPAEA FRUIT OIL')
        ->and(InciName::normalize(null))->toBe('');
});

it('folds a shouting INCI value onto one sentence-case shape', function (): void {
    expect(InciName::display('THEOBROMA CACAO SEED BUTTER'))->toBe('Theobroma cacao seed butter')
        ->and(InciName::display('  Olea   Europaea Fruit Oil '))->toBe('Olea europaea fruit oil');
});

it('keeps the parenthetical botanical or common name exactly as stored', function (): void {
    expect(InciName::display('Oenothera Biennis (Evening Primrose) Oil'))
        ->toBe('Oenothera biennis (Evening Primrose) oil')
        ->and(InciName::display('Aleurites Moluccana (Kukui) Nut Oil'))
        ->toBe('Aleurites moluccana (Kukui) nut oil');
});

it('keeps identifier tokens that stop being identifiers when lower-cased', function (): void {
    expect(InciName::display('CI 77007'))->toBe('CI 77007')
        ->and(InciName::display('CI R102'))->toBe('CI R102')
        ->and(InciName::display('PEG-40 HYDROGENATED CASTOR OIL'))
        ->toBe('PEG-40 hydrogenated castor oil')
        ->and(InciName::display('DISODIUM EDTA'))->toBe('Disodium EDTA');
});

it('treats only all-caps tokens as acronyms so a leaf name still folds', function (): void {
    expect(InciName::display('Black Tea Extract'))->toBe('Black tea extract')
        ->and(InciName::display('Camellia Sinensis (Green Tea) Leaf Extract'))
        ->toBe('Camellia sinensis (Green Tea) leaf extract');
});

it('leaves an already sentence-case INCI value unchanged', function (): void {
    expect(InciName::display('Adansonia digitata seed oil'))->toBe('Adansonia digitata seed oil')
        ->and(InciName::display('Aqua'))->toBe('Aqua');
});

it('returns an empty display value when INCI is missing', function (): void {
    expect(InciName::display(null))->toBe('')
        ->and(InciName::display(''))->toBe('')
        ->and(InciName::display('   '))->toBe('');
});

it('keeps the matching key independent of the display form', function (): void {
    expect(InciName::normalize('Theobroma cacao seed butter'))->toBe('THEOBROMA CACAO SEED BUTTER')
        ->and(InciName::normalize(InciName::display('THEOBROMA CACAO SEED BUTTER')))
        ->toBe(InciName::normalize('theobroma cacao seed butter'));
});
