<?php

use App\Services\IngredientEnrichment\UsIngredientDeclarationService;

it('proposes the FDA botanical label form for a common oil name', function (): void {
    $result = app(UsIngredientDeclarationService::class)->propose([
        'unii' => '4V59G5UW9X',
        'common_name' => 'ARGAN OIL',
        'inci_names' => ['ARGANIA SPINOSA KERNEL OIL'],
        'cas' => [],
    ]);

    expect($result->data)->toMatchArray([
        'market_code' => 'us',
        'declaration_name' => 'Argan (Argania Spinosa) Oil',
        'confidence' => 'supported',
    ])->and($result->evidence[0]['confidence'])->toBe('supported');
});

it('composes the FDA label form when the registry common name is the latin name', function (): void {
    $result = app(UsIngredientDeclarationService::class)->propose(
        candidate: [
            'unii' => '98HPY76U4W',
            'common_name' => 'ADEPS BOVIS',
            'inci_names' => ['ADEPS BOVIS', 'TALLOW'],
            'cas' => ['61789-97-7'],
        ],
        displayName: 'Beef Tallow',
    );

    expect($result->data['declaration_name'])->toBe('Beef (Adeps Bovis) Tallow');
});

it('strips editorial qualifiers from the display name before composing the FDA label', function (): void {
    $result = app(UsIngredientDeclarationService::class)->propose(
        candidate: [
            'unii' => 'WDO4TLS35F',
            'common_name' => 'SCLEROCARYA BIRREA SEED OIL',
            'inci_names' => ['SCLEROCARYA BIRREA SEED OIL'],
            'cas' => [],
        ],
        verifiedInciName: 'SCLEROCARYA BIRREA SEED OIL',
        displayName: 'Organic Virgin Marula Oil',
    );

    expect($result->data['declaration_name'])->toBe('Marula (Sclerocarya Birrea) Oil');
});

it('normalizes punctuation and casing around editorial qualifiers before composing the FDA label', function (string $displayName, string $expected): void {
    $result = app(UsIngredientDeclarationService::class)->propose(
        candidate: [
            'unii' => 'WDO4TLS35F',
            'common_name' => 'SCLEROCARYA BIRREA SEED OIL',
            'inci_names' => ['SCLEROCARYA BIRREA SEED OIL'],
            'cas' => [],
        ],
        verifiedInciName: 'SCLEROCARYA BIRREA SEED OIL',
        displayName: $displayName,
    );

    expect($result->data['declaration_name'])->toBe($expected);
})->with([
    'comma-adjacent qualifiers' => [
        'Organic, Virgin Marula Oil',
        'Marula (Sclerocarya Birrea) Oil',
    ],
    'non-breaking hyphen in the form' => [
        'Organic Virgin Marula‑Oil',
        'Marula (Sclerocarya Birrea) Oil',
    ],
    'non-breaking hyphen in an editorial phrase' => [
        'Cold‑Pressed Organic Marula Oil',
        'Marula (Sclerocarya Birrea) Oil',
    ],
    'parenthesized qualifier' => [
        'Organic (Virgin) Marula Oil',
        'Marula (Sclerocarya Birrea) Oil',
    ],
    'mixed-case qualifiers' => [
        'oRgAnIc vIrGiN mArUlA oIl',
        'Marula (Sclerocarya Birrea) Oil',
    ],
    'uppercase and title-case qualifiers' => [
        'ORGANIC Refined Marula Oil',
        'Marula (Sclerocarya Birrea) Oil',
    ],
    'sweet descriptor remains meaningful' => [
        'Organic Sweet Marula Oil',
        'Sweet Marula (Sclerocarya Birrea) Oil',
    ],
    'high oleic descriptor remains meaningful' => [
        'Organic High Oleic Marula Oil',
        'High Oleic Marula (Sclerocarya Birrea) Oil',
    ],
]);

it('normalizes Unicode dashes in editorial phrases before composing the FDA label', function (string $dash): void {
    $result = app(UsIngredientDeclarationService::class)->propose(
        candidate: [
            'unii' => 'WDO4TLS35F',
            'common_name' => 'SCLEROCARYA BIRREA SEED OIL',
            'inci_names' => ['SCLEROCARYA BIRREA SEED OIL'],
            'cas' => [],
        ],
        verifiedInciName: 'SCLEROCARYA BIRREA SEED OIL',
        displayName: "Organic{$dash}Extra{$dash}Virgin Marula Oil",
    );

    expect($result->data['declaration_name'])->toBe('Marula (Sclerocarya Birrea) Oil');
})->with([
    'hyphen' => '‐',
    'non-breaking hyphen' => '‑',
    'figure dash' => '‒',
    'en dash' => '–',
    'em dash' => '—',
    'horizontal bar' => '―',
    'minus sign' => '−',
]);

it('preserves a meaningful Unicode hyphen in the composed FDA label', function (): void {
    $result = app(UsIngredientDeclarationService::class)->propose(
        candidate: [
            'unii' => 'WDO4TLS35F',
            'common_name' => 'SCLEROCARYA BIRREA SEED OIL',
            'inci_names' => ['SCLEROCARYA BIRREA SEED OIL'],
            'cas' => [],
        ],
        verifiedInciName: 'SCLEROCARYA BIRREA SEED OIL',
        displayName: 'Omega‑3 Marula Oil',
    );

    expect($result->data['declaration_name'])->toBe('Omega‑3 Marula (Sclerocarya Birrea) Oil');
});

it('keeps material-distinguishing adjectives in the composed FDA label', function (): void {
    $result = app(UsIngredientDeclarationService::class)->propose(
        candidate: [
            'unii' => 'SWEETALMOND001',
            'common_name' => 'PRUNUS AMYGDALUS DULCIS OIL',
            'inci_names' => ['PRUNUS AMYGDALUS DULCIS OIL'],
            'cas' => [],
        ],
        verifiedInciName: 'PRUNUS AMYGDALUS DULCIS OIL',
        displayName: 'Organic Sweet Almond Oil',
    );

    expect($result->data['declaration_name'])->toBe('Sweet Almond (Prunus Amygdalus Dulcis) Oil');
});

it('keeps the high oleic descriptor in the composed FDA label', function (): void {
    $result = app(UsIngredientDeclarationService::class)->propose(
        candidate: [
            'unii' => 'SUNFLOWER001',
            'common_name' => 'HELIANTHUS ANNUUS SEED OIL',
            'inci_names' => ['HELIANTHUS ANNUUS SEED OIL'],
            'cas' => [],
        ],
        verifiedInciName: 'HELIANTHUS ANNUUS SEED OIL',
        displayName: 'Organic High Oleic Sunflower Oil',
    );

    expect($result->data['declaration_name'])
        ->toBe('High Oleic Sunflower (Helianthus Annuus) Oil');
});

it('composes the FDA label form for seed-oil botanicals', function (): void {
    $result = app(UsIngredientDeclarationService::class)->propose([
        'unii' => 'H3E878020N',
        'common_name' => 'COTTONSEED OIL',
        'inci_names' => ['GOSSYPIUM HERBACEUM SEED OIL'],
        'cas' => ['8001-29-4'],
    ]);

    expect($result->data['declaration_name'])->toBe('Cottonseed (Gossypium Herbaceum) Oil');
});

it('uses the FDA sweet almond botanical label example', function (): void {
    $result = app(UsIngredientDeclarationService::class)->propose([
        'unii' => '18L9E3U51M',
        'common_name' => 'ALMOND OIL',
        'inci_names' => ['PRUNUS AMYGDALUS DULCIS OIL'],
        'cas' => ['8007-69-0'],
    ]);

    expect($result->data['declaration_name'])
        ->toBe('Sweet Almond (Prunus Amygdalus Dulcis) Oil');
});

it('uses the separately verified canonical INCI for the FDA sweet almond label example', function (): void {
    $result = app(UsIngredientDeclarationService::class)->propose(
        candidate: [
            'unii' => '18L9E3U51M',
            'common_name' => 'ALMOND OIL',
            'inci_names' => [],
            'cas' => ['8007-69-0'],
        ],
        verifiedInciName: 'PRUNUS AMYGDALUS DULCIS OIL',
    );

    expect($result->data['declaration_name'])
        ->toBe('Sweet Almond (Prunus Amygdalus Dulcis) Oil');
});

it('does not propose a bare CI number as a US colour declaration', function (): void {
    $result = app(UsIngredientDeclarationService::class)->propose([
        'unii' => null,
        'common_name' => 'CI 19140',
        'inci_names' => ['CI 19140'],
        'cas' => [],
    ], isColourant: true);

    expect($result->data['declaration_name'])->toBeNull()
        ->and($result->unresolvedQuestions)->not->toBeEmpty();
});

it('describes a missing ordinary US declaration without calling it a colour', function (): void {
    $result = app(UsIngredientDeclarationService::class)->propose([
        'unii' => null,
        'common_name' => null,
        'inci_names' => ['INULIN'],
        'cas' => [],
    ]);

    expect($result->data['declaration_name'])->toBeNull()
        ->and($result->unresolvedQuestions)->toBe([__('ingredient_enrichment.warnings.us_declaration_unresolved')]);
});
