<?php

use App\Data\IngredientSourceStageResult;
use App\Enums\IngredientEnrichmentResearchStage;
use App\Services\IngredientEnrichment\IngredientEnrichmentFactsBuilder;

it('assembles separate EU and US declarations plus multi-value identifiers', function (): void {
    $facts = app(IngredientEnrichmentFactsBuilder::class)->build(
        record: [
            'catalog_key' => 'argan_oil',
            'display_name' => 'Argan oil',
            'inci_name' => null,
            'category' => 'lipids',
            'subcategory' => 'vegetable_oils',
            'identifiers' => [],
        ],
        euStructured: new IngredientSourceStageResult(IngredientEnrichmentResearchStage::EuStructured, 'completed', [
            'candidates' => [[
                'cosing_ref' => '54495',
                'inci_name' => 'ARGANIA SPINOSA KERNEL OIL',
                'cas' => ['223747-87-3', '299184-75-1'],
                'ec' => [],
                'functions' => ['skin_conditioning'],
                'description' => 'Oil obtained from the kernels of Argania spinosa.',
                'confidence' => 'supported',
            ]],
        ], evidence: [['field' => 'source.eu_structured.54495', ...factsSource('structured_mirror', 'supported', 'https://cosingchecker.com/ingredients/54495-argania-spinosa-kernel-oil/')]]),
        euOfficial: new IngredientSourceStageResult(IngredientEnrichmentResearchStage::EuOfficial, 'completed', [
            'matched' => true,
            'common_ingredient_name' => 'ARGANIA SPINOSA KERNEL OIL',
        ], evidence: [['field' => 'proposal.inci_name', ...factsSource('official', 'verified', 'https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=CELEX:32025D1175')]]),
        usIdentity: new IngredientSourceStageResult(IngredientEnrichmentResearchStage::UsIdentity, 'completed', [
            'candidates' => [[
                'unii' => '4V59G5UW9X',
                'common_name' => 'ARGAN OIL',
                'inci_names' => ['ARGANIA SPINOSA KERNEL OIL'],
                'names' => ['ARGAN OIL', 'ARGANIA SPINOSA KERNEL OIL', 'MOROCCAN ARGAN OIL'],
                'cas' => [],
            ]],
        ], evidence: [['field' => 'source.us_identity.4V59G5UW9X', ...factsSource('official', 'verified', 'https://api.fda.gov/other/substance.json')]]),
        usDeclaration: new IngredientSourceStageResult(IngredientEnrichmentResearchStage::UsDeclaration, 'completed', [
            'market_code' => 'us',
            'declaration_name' => 'ARGAN OIL',
            'confidence' => 'supported',
        ], evidence: [['field' => 'proposal.market_labels.us.declaration_name', ...factsSource('official', 'supported', 'https://www.fda.gov/cosmetics/cosmetics-labeling/cosmetic-ingredient-names')]]),
    );

    $identifiers = collect(data_get($facts, 'proposal.identifiers'));
    expect($identifiers->contains(fn (array $row): bool => $row['scheme'] === 'unii'
        && $row['value'] === '4V59G5UW9X'
        && $row['source_tier'] === 'official'))->toBeTrue()
        ->and($identifiers->contains(fn (array $row): bool => $row['scheme'] === 'cas'
            && $row['value'] === '223747-87-3'
            && $row['source_tier'] === 'structured_mirror'))->toBeTrue()
        ->and(data_get($facts, 'proposal.market_labels.0.market_code'))->toBe('eu')
        ->and(data_get($facts, 'proposal.market_labels.1.market_code'))->toBe('us')
        ->and(data_get($facts, 'proposal.aliases.0'))->toMatchArray([
            'locale' => 'und',
            'name' => 'MOROCCAN ARGAN OIL',
            'kind' => 'common',
            'source_tier' => 'official',
        ])
        ->and(collect($facts['evidence'])->contains('field', 'proposal.aliases.0'))->toBeTrue()
        ->and(collect($facts['field_confidence'])->firstWhere('field', 'proposal.inci_name')['confidence'])->toBe('verified');
    expect(data_get($facts, 'editorial_context.identity_description'))
        ->toBe('Oil obtained from the kernels of Argania spinosa.')
        ->and(data_get($facts, 'editorial_context.cosing_functions'))->toBe(['skin_conditioning']);
});

it('uses evidence belonging to the selected EU identity instead of the first search candidate', function (): void {
    $facts = app(IngredientEnrichmentFactsBuilder::class)->build(
        record: [
            'catalog_key' => 'inulin',
            'display_name' => 'Inulin',
            'inci_name' => 'INULIN',
            'category' => 'actives',
            'subcategory' => 'other_actives',
            'identifiers' => [],
        ],
        euStructured: new IngredientSourceStageResult(IngredientEnrichmentResearchStage::EuStructured, 'completed', [
            'candidates' => [
                ['cosing_ref' => '90770', 'inci_name' => 'HYDROXYPROPYLTRIMONIUM INULIN', 'cas' => [], 'ec' => [], 'functions' => []],
                ['cosing_ref' => '56723', 'inci_name' => 'INULIN', 'cas' => ['9005-80-5'], 'ec' => ['232-684-3'], 'functions' => ['skin_conditioning']],
            ],
        ], evidence: [
            ['field' => 'source.eu_structured.90770', ...factsSource('structured_mirror', 'supported', 'https://cosingchecker.com/ingredients/90770-hydroxypropyltrimonium-inulin/')],
            ['field' => 'source.eu_structured.56723', ...factsSource('structured_mirror', 'supported', 'https://cosingchecker.com/ingredients/56723-inulin/')],
        ]),
        euOfficial: new IngredientSourceStageResult(IngredientEnrichmentResearchStage::EuOfficial, 'completed', ['matched' => false]),
        usIdentity: new IngredientSourceStageResult(IngredientEnrichmentResearchStage::UsIdentity, 'completed', ['candidates' => []]),
        usDeclaration: new IngredientSourceStageResult(IngredientEnrichmentResearchStage::UsDeclaration, 'completed', ['declaration_name' => null]),
    );

    expect(data_get($facts, 'proposal.identifiers.0.value'))->toBe('56723')
        ->and(data_get($facts, 'proposal.identifiers.0.source_url'))->toBe('https://cosingchecker.com/ingredients/56723-inulin/')
        ->and(collect($facts['evidence'])->firstWhere('field', 'proposal.inci_name')['source_url'])->toBe('https://cosingchecker.com/ingredients/56723-inulin/');
});

it('keeps an unverified EU identity unresolved without fabricating an EU market label', function (): void {
    $facts = app(IngredientEnrichmentFactsBuilder::class)->build(
        record: [
            'catalog_key' => 'brazil_nut_oil',
            'display_name' => 'Brazil nut oil',
            'inci_name' => 'BERTHOLLETIA EXCELSA SEED OIL',
            'category' => 'lipids',
            'subcategory' => 'vegetable_oils',
            'identifiers' => [],
        ],
        euStructured: new IngredientSourceStageResult(IngredientEnrichmentResearchStage::EuStructured, 'completed', ['candidates' => []]),
        euOfficial: new IngredientSourceStageResult(IngredientEnrichmentResearchStage::EuOfficial, 'completed', ['matched' => false]),
        usIdentity: new IngredientSourceStageResult(IngredientEnrichmentResearchStage::UsIdentity, 'completed', ['candidates' => [[
            'unii' => '0G89T29HO6',
            'common_name' => 'BRAZIL NUT OIL',
            'cas' => [],
        ]]], evidence: [['field' => 'source.us_identity.0G89T29HO6', ...factsSource('official', 'verified', 'https://api.fda.gov/other/substance.json')]]),
        usDeclaration: new IngredientSourceStageResult(IngredientEnrichmentResearchStage::UsDeclaration, 'completed', [
            'market_code' => 'us',
            'declaration_name' => 'BRAZIL NUT OIL',
            'confidence' => 'supported',
        ], evidence: [['field' => 'proposal.market_labels.us.declaration_name', ...factsSource('official', 'supported', 'https://www.fda.gov/cosmetics/cosmetics-labeling/cosmetic-ingredient-names')]]),
    );

    expect(data_get($facts, 'proposal.market_labels'))->toHaveCount(1)
        ->and(data_get($facts, 'proposal.market_labels.0.market_code'))->toBe('us')
        ->and(collect($facts['field_confidence'])->firstWhere('field', 'proposal.inci_name')['confidence'])->toBe('unresolved')
        ->and(collect($facts['evidence'])->contains('field', 'proposal.inci_name'))->toBeFalse()
        ->and($facts['unresolved_questions'])->toContain(__('ingredient_enrichment.warnings.eu_identity_unresolved'));
});

/** @return array<string, mixed> */
function factsSource(string $tier, string $confidence, string $url): array
{
    return [
        'source_name' => 'Test source',
        'source_url' => $url,
        'source_tier' => $tier,
        'confidence' => $confidence,
        'source_version' => 'test-v1',
        'source_updated_at' => null,
        'retrieved_at' => '2026-08-13T12:00:00+00:00',
    ];
}
