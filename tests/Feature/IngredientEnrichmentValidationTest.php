<?php

use App\Enums\IngredientCategory;
use App\Models\Ingredient;
use App\Models\IngredientFunction;
use App\Models\IngredientIdentifier;
use App\Models\IngredientTranslation;
use App\Models\SupportedLocale;
use App\Services\IngredientEnrichment\IngredientEnrichmentJsonl;
use App\Services\IngredientEnrichment\IngredientEnrichmentResultValidator;
use App\Services\IngredientEnrichment\IngredientEnrichmentSnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('produces a stable fingerprint independent of child retrieval order and ignores deferred chemistry', function (): void {
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'ADM-STABLE',
        'category' => IngredientCategory::Other,
        'info_markdown' => "## Overview\nA useful ingredient.\n\n## Formulation use\nUsed in simple formulas.",
    ]);
    IngredientIdentifier::factory()->create([
        'ingredient_id' => $ingredient->id,
        'scheme' => 'cas',
        'value' => '123-45-6',
        'normalized_value' => '123456',
        'is_primary' => true,
    ]);
    SupportedLocale::factory()->create(['code' => 'fr', 'name' => 'French']);
    IngredientTranslation::factory()->create([
        'ingredient_id' => $ingredient->id,
        'locale' => 'fr',
        'display_name' => 'Ingrédient stable',
        'info_markdown' => "## Aperçu\nUn ingrédient utile.\n\n## Utilisation en formulation\nUtilisé dans des formules simples.",
    ]);

    $builder = app(IngredientEnrichmentSnapshotBuilder::class);
    $first = $builder->build($ingredient->fresh());

    $ingredient->update(['source_data' => ['sap' => ['koh_sap_value' => 0.18]]]);
    $second = $builder->build($ingredient->fresh());

    expect($first['fingerprint'])->toBe($second['fingerprint'])
        ->and($first['canonical_json'])->toBe($second['canonical_json']);
});

it('marks a structurally incomplete ingredient until guidance and configured translations exist', function (): void {
    foreach (['de', 'es', 'fr', 'it', 'nl', 'pt_BR'] as $locale) {
        SupportedLocale::factory()->create(['code' => $locale, 'name' => $locale]);
    }

    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Other,
        'info_markdown' => null,
    ]);
    $builder = app(IngredientEnrichmentSnapshotBuilder::class);

    expect($builder->isIncomplete($ingredient))->toBeTrue();

    $ingredient->update([
        'info_markdown' => "## Overview\nA useful ingredient.\n\n## Formulation use\nUsed in simple formulas.",
    ]);
    foreach (['de', 'es', 'fr', 'it', 'nl', 'pt_BR'] as $locale) {
        $headings = config("ingredient-enrichment.guidance.localized_headings.{$locale}");
        IngredientTranslation::factory()->create([
            'ingredient_id' => $ingredient->id,
            'locale' => $locale,
            'display_name' => "Name {$locale}",
            'info_markdown' => "## {$headings['overview']}\nUn ingrédient traduit.\n\n## {$headings['formulation_use']}\nUtilisé dans des formules traduites.",
        ]);
    }

    expect($builder->isIncomplete($ingredient->fresh()))->toBeFalse();
});

it('reports malformed JSONL with its physical line number', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'ingredient-enrichment-');
    file_put_contents($path, "\n{\"catalog_key\":\"ADM-ONE\"}\nnot-json\n");

    $records = app(IngredientEnrichmentJsonl::class)->read($path);

    expect($records)->toHaveCount(2)
        ->and($records[0]['line'])->toBe(2)
        ->and($records[0]['data'])->toBe(['catalog_key' => 'ADM-ONE'])
        ->and($records[0]['error'])->toBeNull()
        ->and($records[1]['line'])->toBe(3)
        ->and($records[1]['data'])->toBeNull()
        ->and($records[1]['error'])->toContain('Line 3');

    unlink($path);
});

it('rejects unknown result fields, incomplete locale coverage, and missing evidence', function (): void {
    foreach (['de', 'es', 'fr', 'it', 'nl', 'pt_BR'] as $locale) {
        SupportedLocale::factory()->create(['code' => $locale, 'name' => $locale]);
    }

    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'ADM-VALIDATE',
        'category' => IngredientCategory::Other,
    ]);
    $result = enrichmentResult($ingredient->catalog_key);
    $result['unexpected'] = true;
    array_pop($result['proposal']['translations']);
    $result['evidence'] = [];

    $report = app(IngredientEnrichmentResultValidator::class)->validate($result, $ingredient);

    expect($report['valid'])->toBeFalse()
        ->and($report['errors'])->toHaveKey('result')
        ->and($report['errors'])->toHaveKey('proposal.translations')
        ->and($report['errors'])->toHaveKey('evidence');
});

it('accepts the bounded result contract for a non-colourant', function (): void {
    foreach (['de', 'es', 'fr', 'it', 'nl', 'pt_BR'] as $locale) {
        SupportedLocale::factory()->create(['code' => $locale, 'name' => $locale]);
    }

    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'ADM-CONTRACT',
        'category' => IngredientCategory::Other,
    ]);
    $result = enrichmentResult($ingredient->catalog_key);
    $result['source_fingerprint'] = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $report = app(IngredientEnrichmentResultValidator::class)->validate($result, $ingredient);

    expect($report['valid'])->toBeTrue()
        ->and($report['normalized']['proposal']['display_name'])->toBe('Contract Ingredient');
});

it('accepts full enrichment results with deferred localization', function (bool $omitTranslations): void {
    config()->set('interface-translations.catalogue_locales', ['de']);

    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'ADM-DEFERRED-LOCALIZATION',
        'category' => IngredientCategory::Other,
    ]);
    $result = enrichmentResult($ingredient->catalog_key);
    $result['source_fingerprint'] = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);

    if ($omitTranslations) {
        unset($result['proposal']['translations']);
    } else {
        $result['proposal']['translations'] = [];
    }

    $report = app(IngredientEnrichmentResultValidator::class)->validate($result, $ingredient);

    expect($report['valid'])->toBeTrue()
        ->and($report['errors'])->not->toHaveKey('proposal.translations');
})->with([
    'empty translations array' => false,
    'translations omitted' => true,
]);

it('accepts full enrichment name translations without guidance text', function (): void {
    config()->set('interface-translations.catalogue_locales', ['de']);

    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'ADM-NAME-ONLY-TRANSLATION',
        'category' => IngredientCategory::Other,
    ]);
    $result = enrichmentResult($ingredient->catalog_key);
    $result['source_fingerprint'] = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $result['proposal']['translations'] = [[
        'locale' => 'de',
        'display_name' => 'Übersetzter Name',
        'saponification_name' => null,
    ]];

    $report = app(IngredientEnrichmentResultValidator::class)->validate($result, $ingredient);

    expect($report['valid'])->toBeTrue()
        ->and($report['normalized']['proposal']['translations'])->toBe([[
            'locale' => 'de',
            'display_name' => 'Übersetzter Name',
            'saponification_name' => null,
        ]]);
});

it('accepts classified guidance evidence and preserves its recommendation metadata', function (): void {
    foreach (['de', 'es', 'fr', 'it', 'nl', 'pt_BR'] as $locale) {
        SupportedLocale::factory()->create(['code' => $locale, 'name' => $locale]);
    }

    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'ADM-CLASSIFIED-GUIDANCE',
        'category' => IngredientCategory::Other,
    ]);
    $result = enrichmentResult($ingredient->catalog_key);
    $result['source_fingerprint'] = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $result['guidance_evidence'] = [[
        'source_name' => 'Example supplier',
        'source_url' => 'https://supplier.example/technical/ingredient.pdf',
        'summary' => 'This exact product grade is recommended at 1–10% in cosmetic formulas.',
        'source_tier' => 'editorial',
        'retrieved_at' => '2026-08-30T00:00:00+00:00',
        'claim_type' => 'usage',
        'source_kind' => 'supplier_technical',
        'scope' => 'product_grade',
        'evidence_kind' => 'formulation_recommendation',
        'usage_application' => 'cosmetics',
        'recommended_min_percent' => '1',
        'recommended_max_percent' => '10',
        'percentage_basis' => 'total_formula',
    ]];

    $report = app(IngredientEnrichmentResultValidator::class)->validate($result, $ingredient);

    expect($report['valid'])->toBeTrue()
        ->and($report['normalized']['guidance_evidence'][0])->toMatchArray([
            'claim_type' => 'usage',
            'scope' => 'product_grade',
            'recommended_min_percent' => '1',
            'recommended_max_percent' => '10',
            'percentage_basis' => 'total_formula',
        ]);
});

it('rejects malformed guidance evidence in full enrichment results', function (): void {
    foreach (['de', 'es', 'fr', 'it', 'nl', 'pt_BR'] as $locale) {
        SupportedLocale::factory()->create(['code' => $locale, 'name' => $locale]);
    }

    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'ADM-MALFORMED-GUIDANCE',
        'category' => IngredientCategory::Other,
    ]);
    $result = enrichmentResult($ingredient->catalog_key);
    $result['source_fingerprint'] = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $result['guidance_evidence'] = [
        [
            'source_name' => '',
            'source_url' => 'https://malformed.example/first',
            'summary' => 'Missing source name.',
            'source_tier' => 'editorial',
        ],
        [
            'source_name' => 'Partial source',
            'source_url' => 'https://malformed.example/second',
            'summary' => 'Partially classified evidence.',
            'source_tier' => 'editorial',
            'claim_type' => 'usage',
        ],
    ];

    $report = app(IngredientEnrichmentResultValidator::class)->validate($result, $ingredient);

    expect($report['valid'])->toBeFalse()
        ->and($report['errors'])->toHaveKey('guidance_evidence.0.source_name')
        ->and($report['errors'])->toHaveKey('guidance_evidence.1.claim_type');
});

it('does not allow field confidence to exceed correlated evidence confidence', function (): void {
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'ADM-CONFIDENCE-CEILING',
        'category' => IngredientCategory::Other,
    ]);
    $result = enrichmentResult($ingredient->catalog_key);
    $result['source_fingerprint'] = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $result['evidence'][0]['confidence'] = 'supported';
    $result['proposal']['market_labels'][0]['confidence'] = 'supported';

    $report = app(IngredientEnrichmentResultValidator::class)->validate($result, $ingredient);

    expect($report['valid'])->toBeFalse()
        ->and($report['errors'])->toHaveKey('field_confidence.0.confidence')
        ->and(collect($report['errors'])->flatten()->join(' '))->toContain('exceed');
});

it('rejects English section headings inside localized guidance', function (): void {
    foreach (['de', 'es', 'fr', 'it', 'nl', 'pt_BR'] as $locale) {
        SupportedLocale::factory()->create(['code' => $locale, 'name' => $locale]);
    }

    $ingredient = Ingredient::factory()->create(['catalog_key' => 'ADM-LOCALIZED-HEADINGS']);
    $result = enrichmentResult($ingredient->catalog_key);
    $result['source_fingerprint'] = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $result['proposal']['translations'][1]['info_markdown'] = "## Overview\nContenido en español.\n\n## Formulation use\nUso en una fórmula.";

    $report = app(IngredientEnrichmentResultValidator::class)->validate($result, $ingredient);

    expect($report['valid'])->toBeFalse()
        ->and($report['errors'])->toHaveKey('proposal.translations.1.info_markdown');
});

it('normalizes the INCI name and EU declaration to sentence case', function (): void {
    foreach (['de', 'es', 'fr', 'it', 'nl', 'pt_BR'] as $locale) {
        SupportedLocale::factory()->create(['code' => $locale, 'name' => $locale]);
    }

    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'ADM-INCI-CASE',
        'category' => IngredientCategory::Other,
    ]);
    $result = enrichmentResult($ingredient->catalog_key);
    $result['source_fingerprint'] = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);

    $report = app(IngredientEnrichmentResultValidator::class)->validate($result, $ingredient);

    expect($report['valid'])->toBeTrue()
        ->and(data_get($report, 'normalized.proposal.inci_name'))->toBe('Contract ingredient')
        ->and(data_get($report, 'normalized.proposal.market_labels.0.declaration_name'))->toBe('Contract ingredient')
        ->and(data_get($report, 'normalized.proposal.market_labels.1.declaration_name'))->toBe('Contract Ingredient');
});

it('requires localized saponification names when soapmaking guidance is enabled', function (): void {
    foreach (['de', 'es', 'fr', 'it', 'nl', 'pt_BR'] as $locale) {
        SupportedLocale::factory()->create(['code' => $locale, 'name' => $locale]);
    }

    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'ADM-SOAP-NAME',
        'category' => IngredientCategory::Lipids,
    ]);
    $result = enrichmentResult($ingredient->catalog_key);
    $result['source_fingerprint'] = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $result['proposal']['category'] = 'lipids';
    $result['proposal']['subcategory'] = 'vegetable_oils';
    $result['proposal']['soapmaking_relevant'] = true;
    $result['proposal']['info_markdown'] .= "\n\n## Soapmaking\nContributes cleansing and lather.";
    $result['proposal']['translations'] = collect($result['proposal']['translations'])
        ->map(fn (array $translation): array => [
            ...$translation,
            'info_markdown' => $translation['info_markdown']."\n\n## Soapmaking\nUsed in soap formulations.",
        ])
        ->all();

    $report = app(IngredientEnrichmentResultValidator::class)->validate($result, $ingredient);

    expect($report['valid'])->toBeFalse()
        ->and($report['errors'])->toHaveKey('proposal.saponification_name')
        ->and($report['errors'])->toHaveKey('proposal.translations.0.saponification_name');
});

it('validates source-backed CosIng functions without invoking predicate strings with collection keys', function (): void {
    foreach (['de', 'es', 'fr', 'it', 'nl', 'pt_BR'] as $locale) {
        SupportedLocale::factory()->create(['code' => $locale, 'name' => $locale]);
    }

    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'ADM-FUNCTION-CONTRACT',
        'category' => IngredientCategory::Other,
    ]);
    IngredientFunction::factory()->create([
        'key' => 'skin_conditioning',
        'is_active' => true,
    ]);
    $result = enrichmentResult($ingredient->catalog_key);
    $result['source_fingerprint'] = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $result['proposal']['cosing_functions'] = [[
        'key' => 'skin_conditioning',
        'source_name' => 'CosIng Checker',
        'source_url' => 'https://cosingchecker.com/ingredients/54495-argania-spinosa-kernel-oil/',
        ...enrichmentSource('structured_mirror', 'supported', '2026-03-21'),
    ]];
    $result['field_confidence'][] = ['field' => 'proposal.cosing_functions.0', 'confidence' => 'supported'];
    $result['evidence'][] = [
        'field' => 'proposal.cosing_functions.0',
        'source_name' => 'CosIng Checker',
        'source_url' => 'https://cosingchecker.com/ingredients/54495-argania-spinosa-kernel-oil/',
        ...enrichmentSource('structured_mirror', 'supported', '2026-03-21'),
    ];

    $report = app(IngredientEnrichmentResultValidator::class)->validate($result, $ingredient);

    expect($report['valid'])->toBeTrue();
});

it('allows an explicitly unresolved INCI proposal to remain reviewable without fabricated evidence', function (): void {
    foreach (['de', 'es', 'fr', 'it', 'nl', 'pt_BR'] as $locale) {
        SupportedLocale::factory()->create(['code' => $locale, 'name' => $locale]);
    }

    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'ADM-UNRESOLVED-INCI',
        'category' => IngredientCategory::Other,
    ]);
    $result = enrichmentResult($ingredient->catalog_key);
    $result['source_fingerprint'] = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $result['proposal']['inci_name'] = null;
    $result['proposal']['market_labels'] = [$result['proposal']['market_labels'][1]];
    $result['field_confidence'] = [
        ['field' => 'proposal.inci_name', 'confidence' => 'unresolved'],
        ['field' => 'proposal.market_labels.0', 'confidence' => 'supported'],
    ];
    $result['evidence'] = [[
        'field' => 'proposal.market_labels.0',
        'source_name' => 'FDA cosmetic ingredient naming guidance',
        'source_url' => 'https://www.fda.gov/cosmetics/cosmetics-labeling/cosmetic-ingredient-names',
        ...enrichmentSource('official', 'supported', '21 CFR 701.3'),
    ]];
    $result['unresolved_questions'] = ['An EU/INCI identity could not be verified.'];

    $report = app(IngredientEnrichmentResultValidator::class)->validate($result, $ingredient);

    expect($report['valid'])->toBeTrue()
        ->and(data_get($report, 'normalized.proposal.inci_name'))->toBeNull();
});

it('accepts source-backed EU and US market declarations in the result contract', function (): void {
    foreach (['de', 'es', 'fr', 'it', 'nl', 'pt_BR'] as $locale) {
        SupportedLocale::factory()->create(['code' => $locale, 'name' => $locale]);
    }

    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'ADM-COLOUR-CONTRACT',
        'category' => IngredientCategory::Colourants,
    ]);
    $result = enrichmentResult($ingredient->catalog_key);
    $result['source_fingerprint'] = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $result['proposal']['inci_name'] = 'CI 77491';
    $result['proposal']['category'] = 'colourants';
    $result['proposal']['subcategory'] = 'mineral_pigments';
    $result['proposal']['market_labels'] = [
        [
            'market_code' => 'eu',
            'declaration_name' => 'CI 77491',
            'source_name' => 'European Commission',
            'source_url' => 'https://single-market-economy.ec.europa.eu/sectors/cosmetics/cosmetic-ingredient-database/cosing-glossary-ingredients_en',
            ...enrichmentSource('official', 'verified', '32025D1175'),
            'reviewed_at' => '2026-08-13',
            'effective_from' => null,
            'effective_until' => null,
        ],
        [
            'market_code' => 'us',
            'declaration_name' => 'Iron Oxides',
            'source_name' => 'U.S. Food and Drug Administration',
            'source_url' => 'https://www.ecfr.gov/current/title-21/chapter-I/subchapter-A/part-73/subpart-C/section-73.2250',
            ...enrichmentSource('official', 'verified', '21 CFR 73.2250'),
            'reviewed_at' => '2026-08-13',
            'effective_from' => null,
            'effective_until' => null,
        ],
    ];
    $result['evidence'] = [
        [
            'field' => 'proposal.inci_name',
            'source_name' => 'European Commission CosIng',
            'source_url' => 'https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=CELEX:32025D1175',
            ...enrichmentSource('official', 'verified', '32025D1175'),
        ],
        [
            'field' => 'proposal.market_labels.0',
            'source_name' => 'European Commission',
            'source_url' => 'https://single-market-economy.ec.europa.eu/sectors/cosmetics/cosmetic-ingredient-database/cosing-glossary-ingredients_en',
            ...enrichmentSource('official', 'verified', '32025D1175'),
        ],
        [
            'field' => 'proposal.market_labels.1',
            'source_name' => 'U.S. Food and Drug Administration',
            'source_url' => 'https://www.ecfr.gov/current/title-21/chapter-I/subchapter-A/part-73/subpart-C/section-73.2250',
            ...enrichmentSource('official', 'verified', '21 CFR 73.2250'),
        ],
    ];

    $report = app(IngredientEnrichmentResultValidator::class)->validate($result, $ingredient);

    expect($report['valid'])->toBeTrue();
});

/**
 * @return array<string, mixed>
 */
function enrichmentResult(string $catalogKey): array
{
    $translations = collect(['de', 'es', 'fr', 'it', 'nl', 'pt_BR'])
        ->map(function (string $locale): array {
            $headings = config("ingredient-enrichment.guidance.localized_headings.{$locale}");

            return [
                'locale' => $locale,
                'display_name' => "Contract {$locale}",
                'saponification_name' => null,
                'info_markdown' => "## {$headings['overview']}\nA translated ingredient.\n\n## {$headings['formulation_use']}\nUsed in translated formulas.",
            ];
        })
        ->all();

    return [
        'format' => 'soapkraft-platform-ingredient-enrichment-result',
        'schema_version' => 2,
        'catalog_key' => $catalogKey,
        'source_fingerprint' => str_repeat('0', 64),
        'proposal' => [
            'display_name' => 'Contract Ingredient',
            'inci_name' => 'CONTRACT INGREDIENT',
            'category' => 'other',
            'subcategory' => null,
            'saponification_name' => null,
            'soap_inci_naoh_name' => null,
            'soap_inci_koh_name' => null,
            'info_markdown' => "## Overview\nA useful contract ingredient.\n\n## Formulation use\nUsed in simple formulas.",
            'soapmaking_relevant' => false,
            'identifiers' => [],
            'cosing_functions' => [],
            'translations' => $translations,
            'market_labels' => [
                [
                    'market_code' => 'eu',
                    'declaration_name' => 'CONTRACT INGREDIENT',
                    'reviewed_at' => null,
                    'effective_from' => null,
                    'effective_until' => null,
                    'source_name' => 'EUR-Lex Common Ingredient Names Glossary',
                    'source_url' => 'https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=CELEX:32025D1175',
                    ...enrichmentSource('official', 'verified', '32025D1175'),
                ],
                [
                    'market_code' => 'us',
                    'declaration_name' => 'Contract Ingredient',
                    'reviewed_at' => null,
                    'effective_from' => null,
                    'effective_until' => null,
                    'source_name' => 'FDA cosmetic ingredient naming guidance',
                    'source_url' => 'https://www.fda.gov/cosmetics/cosmetics-labeling/cosmetic-ingredient-names',
                    ...enrichmentSource('official', 'supported', '21 CFR 701.3'),
                ],
            ],
        ],
        'field_confidence' => [
            ['field' => 'proposal.inci_name', 'confidence' => 'verified'],
            ['field' => 'proposal.market_labels.0', 'confidence' => 'verified'],
            ['field' => 'proposal.market_labels.1', 'confidence' => 'supported'],
        ],
        'evidence' => [
            [
                'field' => 'proposal.inci_name',
                'source_name' => 'EUR-Lex Common Ingredient Names Glossary',
                'source_url' => 'https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=CELEX:32025D1175',
                ...enrichmentSource('official', 'verified', '32025D1175'),
            ],
            [
                'field' => 'proposal.market_labels.0',
                'source_name' => 'EUR-Lex Common Ingredient Names Glossary',
                'source_url' => 'https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=CELEX:32025D1175',
                ...enrichmentSource('official', 'verified', '32025D1175'),
            ],
            [
                'field' => 'proposal.market_labels.1',
                'source_name' => 'FDA cosmetic ingredient naming guidance',
                'source_url' => 'https://www.fda.gov/cosmetics/cosmetics-labeling/cosmetic-ingredient-names',
                ...enrichmentSource('official', 'supported', '21 CFR 701.3'),
            ],
        ],
        'regulatory_findings' => [],
        'confidence' => 'high',
        'warnings' => [],
        'unresolved_questions' => [],
    ];
}

/** @return array<string, mixed> */
function enrichmentSource(string $tier, string $confidence, ?string $version): array
{
    return [
        'source_tier' => $tier,
        'confidence' => $confidence,
        'source_version' => $version,
        'source_updated_at' => null,
        'retrieved_at' => '2026-08-13T12:00:00+00:00',
    ];
}
