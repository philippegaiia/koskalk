<?php

use App\Enums\IngredientCategory;
use App\Models\Ingredient;
use App\Services\IngredientEnrichment\IngredientEnrichmentResultValidator;
use App\Services\IngredientEnrichment\IngredientEnrichmentSnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps an AI-proposed NaOH salt independent from an unresolved KOH salt', function (): void {
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'TRUST-COCONUT',
        'category' => IngredientCategory::Lipids,
        'subcategory' => 'vegetable_oils',
    ]);
    $result = trustResult($ingredient);
    $result['proposal']['soap_inci_naoh_name'] = 'Sodium Cocoate';
    $result['proposal']['soap_inci_koh_name'] = null;
    $result['field_confidence'] = [
        ['field' => 'proposal.inci_name', 'confidence' => 'verified'],
        ['field' => 'proposal.soap_inci_naoh_name', 'confidence' => 'supported'],
        ['field' => 'proposal.soap_inci_koh_name', 'confidence' => 'unresolved'],
    ];
    $result['value_provenance'] = collect($result['value_provenance'])
        ->map(function (array $row): array {
            return match ($row['field']) {
                'proposal.soap_inci_naoh_name' => [
                    ...$row,
                    'kind' => 'ai_proposed',
                    'reasoning' => 'Proposed from the reviewed base identity because no exact official sodium salt entry was located.',
                ],
                default => $row,
            };
        })
        ->all();

    $report = app(IngredientEnrichmentResultValidator::class)->validate($result, $ingredient);

    expect($report['valid'])->toBeTrue()
        ->and(collect($result['value_provenance'])->firstWhere('field', 'proposal.soap_inci_naoh_name')['kind'])->toBe('ai_proposed')
        ->and(collect($result['value_provenance'])->firstWhere('field', 'proposal.soap_inci_koh_name')['kind'])->toBe('unresolved');
});

it('does not allow an AI soap proposal when the base identity is unresolved', function (): void {
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'TRUST-AMBIGUOUS',
        'category' => IngredientCategory::Lipids,
        'subcategory' => 'vegetable_oils',
    ]);
    $result = trustResult($ingredient);
    $result['proposal']['soap_inci_naoh_name'] = 'Sodium Cocoate';
    $result['field_confidence'] = [
        ['field' => 'proposal.inci_name', 'confidence' => 'unresolved'],
        ['field' => 'proposal.soap_inci_naoh_name', 'confidence' => 'unresolved'],
    ];
    $result['value_provenance'] = collect($result['value_provenance'])
        ->map(fn (array $row): array => $row['field'] === 'proposal.soap_inci_naoh_name'
            ? [...$row, 'kind' => 'ai_proposed']
            : ($row['field'] === 'proposal.inci_name' ? [...$row, 'kind' => 'unresolved', 'source_urls' => []] : $row))
        ->all();

    $report = app(IngredientEnrichmentResultValidator::class)->validate($result, $ingredient);

    expect($report['valid'])->toBeFalse()
        ->and($report['errors']['value_provenance.10'] ?? collect($report['errors'])->flatten()->all())
        ->toBeTruthy()
        ->and(collect($report['errors'])->flatten()->join(' '))->toContain('reliable base identity');
});

it('accepts an incomplete intake result without manufacturing taxonomy or a catalog key', function (): void {
    $result = trustResult();
    $result['subject_type'] = 'intake';
    $result['subject_public_id'] = 'intake-public-id';
    $result['catalog_key'] = null;
    $result['proposal']['inci_name'] = null;
    $result['proposal']['category'] = null;
    $result['proposal']['subcategory'] = null;
    $result['field_confidence'] = [
        ['field' => 'proposal.inci_name', 'confidence' => 'unresolved'],
    ];
    $result['value_provenance'] = collect($result['value_provenance'])
        ->map(fn (array $row): array => in_array($row['field'], ['proposal.inci_name', 'proposal.category', 'proposal.subcategory'], true)
            ? [...$row, 'kind' => 'unresolved', 'source_urls' => []]
            : $row)
        ->all();

    $report = app(IngredientEnrichmentResultValidator::class)->validate($result);

    expect($report['valid'])->toBeTrue()
        ->and($report['normalized']['catalog_key'])->toBeNull()
        ->and($report['normalized']['proposal']['category'])->toBeNull();
});

/** @return array<string, mixed> */
function trustResult(?Ingredient $ingredient = null): array
{
    $locales = array_values(config('interface-translations.catalogue_locales', []));
    $translations = collect($locales)->map(function (string $locale): array {
        $headings = config("ingredient-enrichment.guidance.localized_headings.{$locale}");

        return [
            'locale' => $locale,
            'display_name' => 'Coconut '.$locale,
            'saponification_name' => 'Coconut',
            'info_markdown' => "## {$headings['overview']}\nA useful botanical oil.\n\n## {$headings['formulation_use']}\nUse in the oil phase when compatible.\n\n## {$headings['soapmaking']}\nUse with other oils in a balanced soap formula.",
        ];
    })->all();
    $provenance = [
        ['field' => 'proposal.display_name', 'kind' => 'ai_proposed', 'reasoning' => 'Written from reviewed identity facts.', 'source_urls' => []],
        ['field' => 'proposal.inci_name', 'kind' => 'source_confirmed', 'reasoning' => 'Matched to an exact official glossary entry.', 'source_urls' => ['https://eur-lex.europa.eu/eli/dec_impl/2025/1175/oj/eng']],
        ['field' => 'proposal.category', 'kind' => 'reviewer_supplied', 'reasoning' => 'Retained from the reviewed catalogue state.', 'source_urls' => []],
        ['field' => 'proposal.subcategory', 'kind' => 'reviewer_supplied', 'reasoning' => 'Retained from the reviewed catalogue state.', 'source_urls' => []],
        ['field' => 'proposal.saponification_name', 'kind' => 'ai_proposed', 'reasoning' => 'Written as an editorial soapmaking stem.', 'source_urls' => []],
        ['field' => 'proposal.soap_inci_naoh_name', 'kind' => 'unresolved', 'reasoning' => 'No independently verified salt declaration was available.', 'source_urls' => []],
        ['field' => 'proposal.soap_inci_koh_name', 'kind' => 'unresolved', 'reasoning' => 'No independently verified salt declaration was available.', 'source_urls' => []],
        ['field' => 'proposal.info_markdown', 'kind' => 'ai_proposed', 'reasoning' => 'Written from reviewed identity facts.', 'source_urls' => []],
        ['field' => 'proposal.soapmaking_relevant', 'kind' => 'ai_proposed', 'reasoning' => 'Selected from the reviewed material identity.', 'source_urls' => []],
    ];
    foreach (array_keys($translations) as $index) {
        $provenance[] = [
            'field' => "proposal.translations.{$index}",
            'kind' => 'ai_proposed',
            'reasoning' => 'Translated without changing identity facts.',
            'source_urls' => [],
        ];
    }

    $result = [
        'format' => config('ingredient-enrichment.result_format'),
        'schema_version' => (int) config('ingredient-enrichment.schema_version'),
        'subject_type' => 'ingredient',
        'subject_public_id' => $ingredient?->public_id ?? 'ingredient-public-id',
        'catalog_key' => $ingredient?->catalog_key ?? 'TRUST-INTAKE',
        'source_fingerprint' => $ingredient
            ? app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient)
            : str_repeat('0', 64),
        'proposal' => [
            'display_name' => 'Coconut oil',
            'inci_name' => 'Cocos Nucifera Oil',
            'category' => 'lipids',
            'subcategory' => 'vegetable_oils',
            'saponification_name' => 'Coconut',
            'soap_inci_naoh_name' => null,
            'soap_inci_koh_name' => null,
            'info_markdown' => "## Overview\nThis is a botanical fixed oil used as a lipid material.\n\n## Formulation use\nUse it in the oil phase when its handling and compatibility suit the formula.\n\n## Soapmaking\nIt can contribute hardness and cleansing when blended with other oils.",
            'soapmaking_relevant' => true,
            'aliases' => [],
            'identifiers' => [],
            'cosing_functions' => [],
            'translations' => $translations,
            'market_labels' => [],
        ],
        'field_confidence' => [
            ['field' => 'proposal.inci_name', 'confidence' => 'verified'],
        ],
        'value_provenance' => $provenance,
        'evidence' => [[
            'field' => 'proposal.inci_name',
            'source_name' => 'EUR-Lex Common Ingredient Names Glossary',
            'source_url' => 'https://eur-lex.europa.eu/eli/dec_impl/2025/1175/oj/eng',
            'source_tier' => 'official',
            'confidence' => 'verified',
            'source_version' => '2025/1175',
            'source_updated_at' => null,
            'retrieved_at' => '2026-08-16T10:00:00+00:00',
        ]],
        'regulatory_findings' => [],
        'confidence' => 'medium',
        'warnings' => [],
        'unresolved_questions' => [],
    ];

    return $result;
}
