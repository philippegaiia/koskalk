<?php

use App\Services\IngredientEnrichment\UsIngredientDeclarationService;

it('proposes an FDA common name without confusing it with a UNII', function (): void {
    $result = app(UsIngredientDeclarationService::class)->propose([
        'unii' => '4V59G5UW9X',
        'common_name' => 'ARGAN OIL',
        'inci_names' => ['ARGANIA SPINOSA KERNEL OIL'],
        'cas' => [],
    ]);

    expect($result->data)->toMatchArray([
        'market_code' => 'us',
        'declaration_name' => 'ARGAN OIL',
        'confidence' => 'supported',
    ])->and($result->evidence[0]['confidence'])->toBe('supported');
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
