<?php

use App\Services\IngredientEnrichment\IngredientIdentityMatchService;

it('selects a candidate only with exact INCI or shared identifier evidence', function (): void {
    $match = app(IngredientIdentityMatchService::class)->select([
        [
            'inci_name' => 'ARGANIA SPINOSA KERNEL OIL',
            'cas' => ['223747-87-3'],
            'ec' => [],
        ],
    ], [
        'display_name' => 'Argan oil',
        'inci_name' => 'ARGANIA SPINOSA KERNEL OIL',
        'identifiers' => [],
    ]);

    expect($match['candidate']['inci_name'])->toBe('ARGANIA SPINOSA KERNEL OIL')
        ->and($match['conflicts'])->toBe([]);
});

it('rejects material differences instead of using fuzzy identity matching', function (): void {
    $match = app(IngredientIdentityMatchService::class)->select([
        [
            'inci_name' => 'ARGANIA SPINOSA KERNEL OIL UNSAPONIFIABLES',
            'cas' => [],
            'ec' => [],
        ],
    ], [
        'display_name' => 'Argan oil',
        'inci_name' => 'ARGANIA SPINOSA KERNEL OIL',
        'identifiers' => [],
    ]);

    expect($match['candidate'])->toBeNull()
        ->and($match['conflicts'])->toContain('Material difference: unsaponifiables.');
});

it('does not erase parenthetical material qualifiers during identity matching', function (): void {
    $match = app(IngredientIdentityMatchService::class)->select([
        [
            'inci_name' => 'COCOS NUCIFERA OIL',
            'cas' => ['8001-31-8'],
            'ec' => ['232-282-8'],
        ],
        [
            'inci_name' => 'HYDROGENATED COCONUT OIL',
            'cas' => [],
            'ec' => [],
        ],
    ], [
        'display_name' => 'Coconut Oil',
        'inci_name' => 'Cocos Nucifera (Coconut) Oil',
        'identifiers' => [],
    ]);

    expect($match['candidate'])->toBeNull()
        ->and($match['conflicts'])->toContain('Material difference: hydrogenated.');
});

it('keeps a single candidate unresolved when the record has no INCI or shared identifier', function (): void {
    $match = app(IngredientIdentityMatchService::class)->select([
        [
            'inci_name' => 'ARGANIA SPINOSA KERNEL OIL',
            'cas' => ['223747-87-3'],
            'ec' => [],
        ],
    ], [
        'display_name' => 'Argan oil',
        'inci_name' => null,
        'identifiers' => [],
    ]);

    expect($match['candidate'])->toBeNull()
        ->and($match['conflicts'])
        ->toContain('Identity could not be verified from INCI or identifiers.');
});

it('scores every candidate and selects a later exact identifier match', function (): void {
    $match = app(IngredientIdentityMatchService::class)->select([
        [
            'inci_name' => 'SODIUM COCOATE',
            'cas' => ['61789-31-9'],
            'ec' => [],
        ],
        [
            'inci_name' => 'COCOS NUCIFERA OIL',
            'cas' => ['8001-31-8'],
            'ec' => ['232-282-8'],
        ],
    ], [
        'display_name' => 'Coconut oil',
        'inci_name' => null,
        'identifiers' => [['scheme' => 'ec', 'value' => '232-282-8']],
    ]);

    expect($match['candidate']['inci_name'])->toBe('COCOS NUCIFERA OIL')
        ->and($match['candidate']['match_reasons'])->toContain('exact_ec');
});

it('leaves tied identity candidates unresolved instead of choosing the first row', function (): void {
    $match = app(IngredientIdentityMatchService::class)->select([
        ['inci_name' => 'ARGANIA SPINOSA KERNEL OIL', 'cas' => ['1-1-1'], 'ec' => []],
        ['inci_name' => 'ARGANIA SPINOSA KERNEL OIL', 'cas' => ['2-2-2'], 'ec' => []],
    ], [
        'display_name' => 'Argan oil',
        'inci_name' => 'ARGANIA SPINOSA KERNEL OIL',
        'identifiers' => [],
    ]);

    expect($match['candidate'])->toBeNull()
        ->and($match['conflicts'])->toContain('Identity candidates remain ambiguous and require human review.');
});

it('never matches an oil input to a sibling-form record sharing the same stem', function (): void {
    $match = app(IngredientIdentityMatchService::class)->select([
        [
            'common_name' => 'COTTONSEED ACID',
            'cas' => ['8001-29-4', '68308-51-0'],
            'unii' => 'H3E878020N',
        ],
    ], [
        'display_name' => 'Cottonseed Oil',
        'inci_name' => null,
        'identifiers' => [],
    ]);

    expect($match['candidate'])->toBeNull()
        ->and($match['conflicts'])->toContain('Identity candidate material form does not match the ingredient.')
        ->and($match['conflicts'])->toContain('Material difference: acid.');
});

it('still matches an oil input to the seed-oil record', function (): void {
    $match = app(IngredientIdentityMatchService::class)->select([
        ['inci_name' => 'GOSSYPIUM HERBACEUM SEED OIL', 'cas' => ['8001-29-4'], 'ec' => []],
    ], [
        'display_name' => 'Cottonseed Oil',
        'inci_name' => 'GOSSYPIUM HERBACEUM SEED OIL',
        'identifiers' => [],
    ]);

    expect($match['candidate']['inci_name'])->toBe('GOSSYPIUM HERBACEUM SEED OIL')
        ->and($match['conflicts'])->toBe([]);
});

it('rejects ester derivatives for an oil input even when they contain the oil name', function (): void {
    $match = app(IngredientIdentityMatchService::class)->select([
        ['inci_name' => 'PEG-8 COTTONSEED OIL ESTERS', 'cas' => [], 'ec' => []],
    ], [
        'display_name' => 'Cottonseed Oil',
        'inci_name' => 'COTTONSEED OIL',
        'identifiers' => [],
    ]);

    expect($match['candidate'])->toBeNull();
});

it('keeps form-less registry names matchable for inputs with a form', function (): void {
    $match = app(IngredientIdentityMatchService::class)->select([
        ['common_name' => 'ADEPS BOVIS', 'cas' => ['61789-97-7'], 'ec' => []],
    ], [
        'display_name' => 'Beef Tallow',
        'inci_name' => null,
        'identifiers' => [['scheme' => 'cas', 'value' => '61789-97-7']],
    ]);

    expect($match['candidate']['common_name'])->toBe('ADEPS BOVIS')
        ->and($match['candidate']['match_reasons'])->toContain('exact_cas');
});
