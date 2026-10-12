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

it('preserves mixed-case parenthetical botanical or common names', function (): void {
    expect(InciName::display('Oenothera Biennis (Evening Primrose) Oil'))
        ->toBe('Oenothera biennis (Evening Primrose) oil')
        ->and(InciName::display('Aleurites Moluccana (Kukui) Nut Oil'))
        ->toBe('Aleurites moluccana (Kukui) nut oil');
});

it('formats all-caps parenthetical names without changing identifier tokens', function (string $stored, string $display): void {
    expect(InciName::display($stored))->toBe($display)
        ->and(InciName::normalize(InciName::display($stored)))->toBe(InciName::normalize($stored));
})->with([
    'sunflower' => ['HELIANTHUS ANNUUS (SUNFLOWER) SEED OIL', 'Helianthus annuus (Sunflower) seed oil'],
    'shea' => ['BUTYROSPERMUM PARKII (SHEA) SEED BUTTER', 'Butyrospermum parkii (Shea) seed butter'],
    'coconut' => ['COCOS NUCIFERA (COCONUT) OIL', 'Cocos nucifera (Coconut) oil'],
    'multiple words' => ['OENOTHERA BIENNIS (EVENING PRIMROSE) OIL', 'Oenothera biennis (Evening Primrose) oil'],
    'hyphenated name' => ['ROSA CANINA (DOG-ROSE) FRUIT OIL', 'Rosa canina (Dog-Rose) fruit oil'],
    'identifiers' => ['INGREDIENT (EDTA/TEA PEG-40 CI 77007)', 'Ingredient (EDTA/TEA PEG-40 CI 77007)'],
    'names and identifiers' => ['INGREDIENT (SUNFLOWER / EDTA)', 'Ingredient (Sunflower / EDTA)'],
]);

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
